<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Health records: what was given, and for how long the cow's milk / meat must not be sold.
// The clear dates are worked out when the record is saved (AnimalEvent::computeClearDates), and
// MilkLedger locks every milking inside the milk withdrawal as discarded (milk_logs.withdrawal_event_id).
return new class extends Migration
{
    public function up()
    {
        Schema::table('animal_events', function (Blueprint $table) {
            $table->string('product', 80)->nullable()->after('details');          // drug / vaccine name
            $table->string('dose', 40)->nullable()->after('product');
            $table->unsignedTinyInteger('course_days')->default(1)->after('dose'); // withdrawal counts from the last dose
            $table->unsignedSmallInteger('milk_withdrawal_hours')->nullable()->after('course_days');
            $table->unsignedSmallInteger('meat_withdrawal_days')->nullable()->after('milk_withdrawal_hours');
            $table->date('milk_clear_on')->nullable()->after('meat_withdrawal_days');   // first day the milk may be sold
            $table->date('meat_clear_on')->nullable()->after('milk_clear_on');          // first day it may go for slaughter
            $table->date('next_due_on')->nullable()->after('meat_clear_on');            // booster / next deworming
            $table->string('given_by', 80)->nullable()->after('next_due_on');           // vet or person who gave it

            $table->index(['animal_id', 'milk_clear_on']);
            $table->index('next_due_on');
        });

        Schema::table('milk_logs', function (Blueprint $table) {
            // Set when the milking fell inside a treatment's milk withdrawal: discarded is forced on
            $table->uuid('withdrawal_event_id')->nullable()->after('discarded');
            $table->foreign('withdrawal_event_id')->references('id')->on('animal_events')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('milk_logs', function (Blueprint $table) {
            $table->dropForeign(['withdrawal_event_id']);
            $table->dropColumn('withdrawal_event_id');
        });

        Schema::table('animal_events', function (Blueprint $table) {
            $table->dropIndex(['animal_id', 'milk_clear_on']);
            $table->dropIndex(['next_due_on']);
            $table->dropColumn([
                'product', 'dose', 'course_days', 'milk_withdrawal_hours', 'meat_withdrawal_days',
                'milk_clear_on', 'meat_clear_on', 'next_due_on', 'given_by',
            ]);
        });
    }
};
