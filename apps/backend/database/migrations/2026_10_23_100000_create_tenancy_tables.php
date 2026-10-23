<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#0F4C81');
            $table->string('secondary_color', 7)->default('#1B998B');
            $table->string('accent_color', 7)->default('#F4A259');
            $table->string('support_email')->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('document_footer', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->jsonb('roles');
            // Only the SHA-256 of the token is stored; the plain token travels in the e-mail link.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('tenant_brandings');
    }
};
