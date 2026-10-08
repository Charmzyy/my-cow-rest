<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A vet / extension officer's application and licence details. The user only becomes
// role "officer" once an admin approves this profile.
return new class extends Migration
{
    public function up()
    {
        Schema::create('officer_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('licence_number', 40)->unique();   // Kenya Veterinary Board registration no.
            $table->enum('cadre', ['vet', 'para_vet', 'extension']);
            $table->string('phone', 20);
            $table->unsignedSmallInteger('county_id');          // county they cover
            $table->string('organisation', 120)->nullable();   // county govt, practice, co-op…
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->text('review_note')->nullable();           // reason when rejected/suspended
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('county_id')->references('id')->on('counties');
            $table->index(['status', 'county_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('officer_profiles');
    }
};
