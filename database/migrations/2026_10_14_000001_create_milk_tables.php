<?php

use App\Support\Qr;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Milk: per-cow, per-session logs; a per-cow daily rollup that charts read; lactations derived from
// calving / dry-off records; where the milk went (co-op, local sale, home, calves); QR codes for ear tags.
return new class extends Migration
{
    public function up()
    {
        Schema::table('holdings', function (Blueprint $table) {
            $table->unsignedTinyInteger('milkings_per_day')->default(2)->after('location_source');   // 1, 2 or 3
        });

        // Short code printed as a QR on the ear tag / collar sticker: opens the cow in the app
        Schema::table('animals', function (Blueprint $table) {
            $table->char('qr_code', 8)->nullable()->unique()->after('tag_number');
        });
        $taken = [];
        foreach (DB::table('animals')->pluck('id') as $id) {
            do {
                $code = Qr::randomCode(8);
            } while (isset($taken[$code]));
            $taken[$code] = true;
            DB::table('animals')->where('id', $id)->update(['qr_code' => $code]);
        }

        // New record types for the breeding cycle
        DB::statement("ALTER TABLE animal_events MODIFY type ENUM('vaccination', 'deworming', 'treatment', 'ai_service', 'calving', 'dry_off', 'heat', 'pregnancy_check', 'weight', 'other') NOT NULL");

        // One row per calving; dried_on closes it. Rebuilt from the animal's events (LactationBook).
        Schema::create('lactations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('animal_id');
            $table->unsignedTinyInteger('number');           // 1st, 2nd… recorded calving
            $table->date('calved_on');
            $table->date('dried_on')->nullable();            // null = still in milk
            $table->timestamps();

            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->unique(['animal_id', 'calved_on']);
        });

        // One cow, one milking. The id may come from the phone (offline sync later), so retries never double-count.
        Schema::create('milk_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('animal_id');
            $table->uuid('holding_id');                      // the farm where it was milked
            $table->date('milked_on');
            $table->enum('session', ['am', 'midday', 'pm']);
            $table->decimal('litres', 5, 2);
            $table->boolean('discarded')->default(false);    // not sellable: sick / under treatment / colostrum
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['animal_id', 'milked_on', 'session']);
            $table->index(['holding_id', 'milked_on']);
            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        // Per cow per day, kept up to date on every write (MilkLedger). Charts and reports read this, not milk_logs.
        Schema::create('milk_daily', function (Blueprint $table) {
            $table->uuid('animal_id');
            $table->date('day');
            $table->uuid('holding_id');
            $table->decimal('litres', 6, 2);                 // everything milked
            $table->decimal('discarded', 6, 2)->default(0);  // of which not sellable
            $table->unsignedTinyInteger('sessions');

            $table->primary(['animal_id', 'day']);
            $table->index(['holding_id', 'day']);
            $table->foreign('animal_id')->references('id')->on('animals')->cascadeOnDelete();
            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
        });

        // Who the farm sells to, with their usual price
        Schema::create('milk_buyers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('holding_id');
            $table->string('name', 80);
            $table->enum('kind', ['coop', 'local']);         // co-operative / dairy, or a local buyer
            $table->decimal('price_per_litre', 7, 2)->nullable();   // KES
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
            $table->index(['holding_id', 'active']);
        });

        // Where the milk went
        Schema::create('milk_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('holding_id');
            $table->date('day');
            $table->enum('session', ['am', 'midday', 'pm'])->nullable();
            $table->enum('kind', ['coop', 'sale', 'home', 'calves']);
            $table->uuid('buyer_id')->nullable();
            $table->decimal('litres', 7, 2);
            $table->decimal('rejected_litres', 7, 2)->default(0);   // turned away at the co-op (quality)
            $table->decimal('price_per_litre', 7, 2)->nullable();   // KES; null for home / calves
            $table->boolean('paid')->default(false);
            $table->string('note', 160)->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['holding_id', 'day']);
            $table->foreign('holding_id')->references('id')->on('holdings')->cascadeOnDelete();
            $table->foreign('buyer_id')->references('id')->on('milk_buyers')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('milk_deliveries');
        Schema::dropIfExists('milk_buyers');
        Schema::dropIfExists('milk_daily');
        Schema::dropIfExists('milk_logs');
        Schema::dropIfExists('lactations');
        DB::table('animal_events')->whereIn('type', ['dry_off', 'heat', 'pregnancy_check'])->update(['type' => 'other']);
        DB::statement("ALTER TABLE animal_events MODIFY type ENUM('vaccination', 'deworming', 'treatment', 'ai_service', 'calving', 'weight', 'other') NOT NULL");
        Schema::table('animals', function (Blueprint $table) {
            $table->dropUnique(['qr_code']);
            $table->dropColumn('qr_code');
        });
        Schema::table('holdings', function (Blueprint $table) {
            $table->dropColumn('milkings_per_day');
        });
    }
};
