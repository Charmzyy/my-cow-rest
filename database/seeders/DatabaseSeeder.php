<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data the app needs to work. Safe to re-run: php artisan db:seed --force
     */
    public function run()
    {
        $this->call(CountySeeder::class);
    }
}
