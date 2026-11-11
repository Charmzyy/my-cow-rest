<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Offline recording. Phones keep entries made without signal and send them later, so:
// - milk_logs.entered_at: when the milk was typed in on the phone (not when it reached us).
//   The latest typed entry for a cow's milking wins, whatever order the phones sync in.
// - milk_clashes: when two different people's entries for the same milking disagreed, the one
//   that lost is kept here for the farm's owner and managers to check ("Latest wins, flagged").
return new class extends Migration
{
    public function up()
    {
        Schema::table('milk_logs', function (Blueprint $table) {
            $table->timestamp('entered_at')->nullable()->after('recorded_by');
        });

        Schema::create('milk_clashes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('holding_id');
            $table->uuid('animal_id');
            $table->date('milked_on');
            $table->enum('session', ['am', 'midday', 'pm']);
            // what stayed in the records
            $table->decimal('kept_litres', 5, 2)->nullable();      // null = the entry was cleared
            $table->boolean('kept_discarded')->default(false);
            $table->uuid('kept_by')->nullable();
            $table->timestamp('kept_at')->nullable();
            // what was replaced or ignored
            $table->decimal('lost_litres', 5, 2)->nullable();
            $table->boolean('lost_discarded')->default(false);
            $table->uuid('lost_by')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('seen_at')->nullable();              // an owner/manager marked it checked
            $table->timestamps();

            $table->index(['holding_id', 'seen_at']);
            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('kept_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('lost_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('milk_clashes');
        Schema::table('milk_logs', function (Blueprint $table) {
            $table->dropColumn('entered_at');
        });
    }
};
