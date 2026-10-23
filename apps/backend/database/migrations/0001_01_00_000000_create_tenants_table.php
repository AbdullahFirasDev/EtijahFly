<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->char('country_code', 2)->default('IQ');
            $table->char('default_currency', 3)->default('IQD');
            $table->string('contact_email');
            $table->string('contact_phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('timezone', 64)->default('Asia/Baghdad');
            $table->string('locale', 5)->default('ar');
            $table->jsonb('settings')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
