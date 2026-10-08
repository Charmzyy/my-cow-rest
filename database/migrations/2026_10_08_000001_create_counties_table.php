<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kenya's 47 counties (seeded by CountySeeder). Sub-county / ward / village are free text for now.
return new class extends Migration
{
    public function up()
    {
        Schema::create('counties', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();   // official county code 1-47
            $table->string('name', 60)->unique();
        });
    }

    public function down()
    {
        Schema::dropIfExists('counties');
    }
};
