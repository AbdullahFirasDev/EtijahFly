<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->string('type', 20); // invoice | credit_note
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('subtotal', 16, 2);
            $table->decimal('total', 16, 2);
            $table->jsonb('lines');
            $table->string('status', 20)->default('issued'); // issued | credited
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'issued_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->string('method', 30); // bank_transfer | cash | cheque | gateway
            $table->string('gateway', 40)->default('manual');
            $table->string('external_reference')->nullable();
            $table->date('paid_at');
            $table->string('proof_path')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->string('notes', 1000)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 1000)->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });

        // Manual balance corrections. Require a second user to approve (four-eyes).
        Schema::create('financial_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('direction', 6); // credit (agency receives) | debit (agency is charged)
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 1000)->nullable();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('booking_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 30); // booking_confirmation | e_ticket | invoice | credit_note
            $table->string('path');
            $table->string('filename');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('booking_status', 20)->nullable(); // status at generation time
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_documents');
        Schema::dropIfExists('financial_adjustments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
