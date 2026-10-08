<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A farmer's request to have one animal certified, and the officer's decision on it.
return new class extends Migration
{
    public function up()
    {
        Schema::create('certification_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('animal_id');
            $table->uuid('requested_by');
            $table->uuid('assigned_officer_id')->nullable();
            $table->enum('status', ['submitted', 'assigned', 'in_review', 'needs_info', 'approved', 'rejected', 'cancelled'])
                ->default('submitted');
            $table->enum('inspection_method', ['photo', 'visit'])->default('photo');
            $table->text('owner_note')->nullable();

            // Officer's decision
            $table->string('approved_breed', 60)->nullable();
            $table->enum('purity', ['purebred', 'crossbreed'])->nullable();
            $table->string('purity_note', 120)->nullable();    // e.g. "Friesian × Boran, ~50%"
            $table->text('officer_note')->nullable();          // shown to the farmer (needs info / reject reason / remarks)

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_officer_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'created_at']);
            $table->index(['assigned_officer_id', 'status']);
            $table->index(['animal_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('certification_requests');
    }
};
