<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table) {
            $table->id();
            $table->string('iata_code', 2)->nullable()->index();
            $table->string('icao_code', 3)->nullable()->unique();
            $table->string('name');
            $table->string('country')->nullable();
            $table->string('founded_year', 4)->nullable();
            $table->string('home_airport')->nullable();
            $table->jsonb('fleet')->nullable();
            $table->string('source', 30)->default('manual'); // airlines_api | manual
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->char('iata_code', 3)->unique();
            $table->char('icao_code', 4)->nullable();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('city_en');
            $table->string('city_ar')->nullable();
            $table->char('country_code', 2)->index();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
        Schema::dropIfExists('airlines');
    }
};
