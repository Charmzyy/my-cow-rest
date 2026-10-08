<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per registered animal: the record a vet certifies and a buyer verifies.
return new class extends Migration
{
    public function up()
    {
        Schema::create('animals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');

            // Identity
            $table->string('tag_number', 40)->unique();            // ear tag, as printed
            $table->string('national_id', 60)->nullable();          // national livestock ID, if issued
            $table->string('name', 80)->nullable();
            $table->enum('sex', ['female', 'male']);
            $table->date('date_of_birth')->nullable();
            $table->unsignedSmallInteger('age_estimate_months')->nullable(); // when DOB unknown
            $table->string('colour', 120)->nullable();               // colour & markings
            $table->enum('purpose', ['dairy', 'beef', 'dual'])->nullable();
            $table->string('breed_claimed', 60)->nullable();         // what the owner believes

            // Breeding
            $table->string('sire_name', 80)->nullable();
            $table->string('sire_tag', 40)->nullable();
            $table->string('dam_name', 80)->nullable();
            $table->string('dam_tag', 40)->nullable();
            $table->string('ai_straw_code', 60)->nullable();         // artificial insemination straw

            // Production
            $table->decimal('weight_kg', 6, 1)->nullable();
            $table->decimal('body_condition_score', 2, 1)->nullable(); // 1-5
            $table->unsignedTinyInteger('lactation_number')->nullable();
            $table->decimal('milk_litres_per_day', 5, 1)->nullable();

            // Location
            $table->unsignedSmallInteger('county_id');
            $table->string('sub_county', 80)->nullable();
            $table->string('ward', 80)->nullable();
            $table->string('village', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Status + AI summary across this animal's photos (refreshed on each upload)
            $table->enum('status', ['active', 'sold', 'dead'])->default('active');
            $table->string('ai_breed', 60)->nullable();
            $table->float('ai_confidence')->nullable();
            $table->unsignedTinyInteger('ai_photos_agreeing')->nullable();
            $table->unsignedTinyInteger('ai_photos_total')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('county_id')->references('id')->on('counties');
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('animals');
    }
};
