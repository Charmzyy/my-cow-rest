<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Who works on each farm, and in what role (owner / manager / worker / vet). Permissions on a
// farm's animals come from this table, not from animals.owner_id (see FarmMember::ABILITIES).
// Also: farm workers sign in with phone + PIN, so users.email becomes optional.
return new class extends Migration
{
    public function up()
    {
        Schema::create('farm_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('holding_id');
            $table->uuid('user_id');
            $table->enum('role', ['owner', 'manager', 'worker', 'vet']);
            $table->uuid('added_by')->nullable();
            $table->timestamps();

            $table->unique(['holding_id', 'user_id']);           // one role per person per farm
            $table->index(['user_id', 'role']);
            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
        });

        // Every existing farm's owner becomes its "owner" member
        DB::statement("
            INSERT INTO farm_members (id, holding_id, user_id, role, created_at, updated_at)
            SELECT UUID(), id, owner_id, 'owner', NOW(), NOW() FROM holdings
        ");

        // Workers have no email. MySQL allows many NULLs in a unique column.
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
        Schema::table('users', function (Blueprint $table) {
            // The phone a worker signs in with, normalised (+2547XXXXXXXX). Only PIN accounts have it.
            $table->string('login_phone', 16)->nullable()->unique()->after('phone');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login_phone']);
            $table->dropColumn('login_phone');
        });
        // Email can only become required again once the email-less (worker) accounts are gone
        DB::table('users')->whereNull('email')->delete();
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NOT NULL');

        Schema::dropIfExists('farm_members');
    }
};
