<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Reference data is safe everywhere; demo tenants/users only in local & testing. */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            ProviderSeeder::class,
            AirportSeeder::class,
            AirlineSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
