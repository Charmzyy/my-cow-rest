<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The issued certificate: a numbered, verifiable record created when an officer approves a request.
// What was certified is frozen in `snapshot`, so later edits to the animal never change a certificate.
return new class extends Migration
{
    public function up()
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('certificate_no', 30)->unique();          // MC-KE-2026-000123
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('serial');                       // per-year running number
            $table->string('verification_code', 16)->unique();       // random; what the QR code carries

            $table->uuid('animal_id');
            $table->uuid('request_id')->unique();                    // one certificate per approved request
            $table->uuid('owner_id');
            $table->uuid('officer_id')->nullable();

            $table->string('breed', 60);
            $table->enum('purity', ['purebred', 'crossbreed']);
            $table->string('purity_note', 120)->nullable();
            $table->json('snapshot');                                // animal, owner, officer facts at issue time
            $table->char('content_hash', 64);                        // sha256 of number + code + snapshot

            $table->enum('status', ['valid', 'revoked', 'superseded'])->default('valid');
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['year', 'serial']);
            $table->index(['animal_id', 'status']);
            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('certification_requests')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('officer_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('certificates');
    }
};
