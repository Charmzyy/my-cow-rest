<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('userposts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->longText('cow_name');
            $table->string('image');
            $table->longText('predicted_class')->nullable();
            $table->float('confidence')->nullable();
            $table->boolean('is_verified')->nullable();
            $table->longText('reason')->nullable();
            $table->timestamps();
            // Define foreign key constraint referencing the `id` column in `users` table
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('userposts');
    }
};
