<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Guided photos: one per angle per animal (re-uploading an angle replaces it).
return new class extends Migration
{
    public function up()
    {
        Schema::create('animal_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('animal_id');
            $table->enum('angle', ['left', 'right', 'front', 'rear', 'face', 'ear_tag', 'udder']);
            $table->string('path');
            $table->char('image_hash', 64);
            $table->string('ai_breed', 60)->nullable();    // null for ear tag / udder, or if the model was down
            $table->float('ai_confidence')->nullable();
            $table->timestamps();

            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->unique(['animal_id', 'angle']);
            $table->index('image_hash');   // one photo may only ever belong to one animal
        });
    }

    public function down()
    {
        Schema::dropIfExists('animal_photos');
    }
};
