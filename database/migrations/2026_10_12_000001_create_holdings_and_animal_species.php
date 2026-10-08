<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Farms ("holdings"): where animals are kept. Location now belongs to the farm and is copied
// onto each animal, so existing code that reads animals.county_id keeps working.
// Also: animals.species, so goats/sheep/pigs/camels can be added without touching old rows.
return new class extends Migration
{
    public function up()
    {
        Schema::create('holdings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->uuid('organization_id')->nullable();          // organisation workspaces (v9)
            $table->string('name', 80);
            $table->unsignedSmallInteger('county_id');
            $table->string('sub_county', 80)->nullable();
            $table->string('ward', 80)->nullable();
            $table->string('village', 80)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('location_accuracy_m')->nullable();
            $table->enum('location_source', ['gps', 'map'])->nullable();
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('county_id')->references('id')->on('counties');
            $table->index(['owner_id', 'created_at']);
        });

        Schema::table('animals', function (Blueprint $table) {
            $table->enum('species', ['cattle', 'goat', 'sheep', 'pig', 'camel'])->default('cattle')->after('owner_id');
            $table->uuid('holding_id')->nullable()->after('species');
            $table->foreign('holding_id')->references('id')->on('holdings')->nullOnDelete();
        });

        // Existing animals: one farm per owner + county + village they used, so nothing is lost
        $groups = DB::table('animals')
            ->select('owner_id', 'county_id', 'sub_county', 'ward', 'village')
            ->selectRaw('MAX(latitude) AS latitude, MAX(longitude) AS longitude')
            ->groupBy('owner_id', 'county_id', 'sub_county', 'ward', 'village')
            ->get();

        foreach ($groups as $g) {
            $countyName = DB::table('counties')->where('id', $g->county_id)->value('name');
            $id = (string) Str::uuid();
            DB::table('holdings')->insert([
                'id' => $id,
                'owner_id' => $g->owner_id,
                'name' => $g->village ?: "Farm in {$countyName}",
                'county_id' => $g->county_id,
                'sub_county' => $g->sub_county,
                'ward' => $g->ward,
                'village' => $g->village,
                'latitude' => $g->latitude,
                'longitude' => $g->longitude,
                'location_source' => $g->latitude ? 'gps' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('animals')
                ->where('owner_id', $g->owner_id)
                ->where('county_id', $g->county_id)
                ->where(fn ($q) => $g->sub_county === null ? $q->whereNull('sub_county') : $q->where('sub_county', $g->sub_county))
                ->where(fn ($q) => $g->ward === null ? $q->whereNull('ward') : $q->where('ward', $g->ward))
                ->where(fn ($q) => $g->village === null ? $q->whereNull('village') : $q->where('village', $g->village))
                ->update(['holding_id' => $id]);
        }
    }

    public function down()
    {
        Schema::table('animals', function (Blueprint $table) {
            $table->dropForeign(['holding_id']);
            $table->dropColumn(['holding_id', 'species']);
        });
        Schema::dropIfExists('holdings');
    }
};
