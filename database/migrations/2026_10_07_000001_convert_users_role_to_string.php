<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// users.role was a boolean (0 = farmer, 1 = admin). The commercial app needs more roles
// (farmer, officer, org_admin, admin), so turn it into a string and translate existing rows.
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'farmer'");
        DB::table('users')->where('role', '1')->update(['role' => 'admin']);
        DB::table('users')->whereIn('role', ['0', ''])->update(['role' => 'farmer']);
    }

    public function down()
    {
        DB::table('users')->where('role', 'admin')->update(['role' => '1']);
        DB::table('users')->where('role', '!=', '1')->update(['role' => '0']);
        DB::statement('ALTER TABLE users MODIFY role TINYINT(1) NOT NULL DEFAULT 0');
    }
};
