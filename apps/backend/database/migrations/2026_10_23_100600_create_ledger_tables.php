<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Double-entry ledger. Agency wallets are tenant accounts; platform accounts
        // (supplier payable, markup revenue, ...) have tenant_id NULL. One account per currency.
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('code', 40); // AGENCY_WALLET, SUPPLIER_PAYABLE, MARKUP_REVENUE, ...
            $table->string('name');
            $table->string('normal_side', 6); // debit | credit
            $table->char('currency', 3);
            // Cached balance from the account holder's normal side; kept in sync under row lock.
            $table->decimal('balance', 16, 2)->default(0);
            $table->decimal('credit_limit', 16, 2)->default(0);
            $table->decimal('low_balance_threshold', 16, 2)->nullable();
            $table->string('status', 20)->default('active'); // active | frozen
            $table->timestamps();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('type', 40);
            $table->char('currency', 3);
            $table->decimal('amount', 16, 2); // sum of debits (= sum of credits)
            $table->nullableMorphs('reference');
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('description', 500);
            $table->decimal('fx_rate', 18, 6)->nullable();
            $table->char('fx_source_currency', 3)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('direction', 6); // debit | credit
            $table->decimal('amount', 16, 2);
            $table->decimal('balance_after', 16, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ledger_account_id', 'id']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unique(['name', 'year']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // NULLS NOT DISTINCT so platform accounts (tenant_id NULL) are unique per code/currency too.
            DB::statement('CREATE UNIQUE INDEX ledger_accounts_owner_code_currency_unique ON ledger_accounts (tenant_id, code, currency) NULLS NOT DISTINCT');
            DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction CHECK (direction IN ('debit','credit'))");
            DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_credit_limit CHECK (credit_limit >= 0)');
        } else {
            Schema::table('ledger_accounts', fn (Blueprint $table) => $table->unique(['tenant_id', 'code', 'currency']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
