<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SHA-256 of the uploaded photo, so the same person sending the same photo twice
// (double tap, slow network retry) gets the first result back instead of a duplicate record.
return new class extends Migration
{
    public function up()
    {
        Schema::table('userposts', function (Blueprint $table) {
            $table->char('image_hash', 64)->nullable()->after('image');
            $table->index(['user_id', 'image_hash']);
        });
    }

    public function down()
    {
        Schema::table('userposts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'image_hash']);
            $table->dropColumn('image_hash');
        });
    }
};
