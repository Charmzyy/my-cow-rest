<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An animal's dated history: health, breeding and growth records the owner keeps over time.
// Officers see it during review; buyers will see it on the paid verification tier (Phase 2).
return new class extends Migration
{
    public function up()
    {
        Schema::create('animal_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('animal_id');
            $table->uuid('recorded_by')->nullable();
            $table->enum('type', ['vaccination', 'deworming', 'treatment', 'ai_service', 'calving', 'weight', 'other']);
            $table->date('event_date');
            $table->string('details', 160)->nullable();     // vaccine / drug / bull or straw / calf tag…
            $table->decimal('value', 7, 1)->nullable();     // weight in kg (type = weight)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['animal_id', 'event_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('animal_events');
    }
};
