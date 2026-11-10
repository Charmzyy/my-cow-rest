<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Organisations: the tenant above the farm. A co-op or company runs many farms and people as one
// account; a solo farmer gets a "personal" organisation automatically. Farm access still comes
// from farm_members — the organisation is the workspace those farms are grouped in.
return new class extends Migration
{
    public function up()
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->enum('type', ['personal', 'coop', 'company'])->default('personal');
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();   // #rrggbb, checked for contrast before saving
            $table->string('plan', 30)->default('free');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('organisation_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id');
            $table->uuid('user_id');
            $table->enum('role', ['owner', 'admin', 'member']);
            $table->uuid('added_by')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
            $table->index(['user_id', 'role']);
            $table->foreign('organisation_id')->references('id')->on('organisations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
        });

        foreach (['holdings', 'animals'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Nullable so the backfill below can run; the app always sets it from here on
                $table->uuid('organisation_id')->nullable()->after('id')->index();
                $table->foreign('organisation_id')->references('id')->on('organisations');
            });
        }

        $this->backfill();
    }

    /** One personal organisation per existing farm/animal owner; their farms and animals move into it. */
    private function backfill(): void
    {
        $now = now();
        $ownerIds = DB::table('holdings')->pluck('owner_id')
            ->merge(DB::table('animals')->pluck('owner_id'))
            ->filter()->unique()->values();

        foreach ($ownerIds->chunk(200) as $chunk) {
            foreach (DB::table('users')->whereIn('id', $chunk)->get(['id', 'fullname']) as $user) {
                $orgId = (string) Str::uuid();
                $name = trim(($user->fullname ?: 'My') . '’s farms');
                DB::table('organisations')->insert([
                    'id' => $orgId, 'name' => Str::limit($name, 120, ''), 'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
                    'type' => 'personal', 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('organisation_members')->insert([
                    'id' => (string) Str::uuid(), 'organisation_id' => $orgId, 'user_id' => $user->id,
                    'role' => 'owner', 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('holdings')->where('owner_id', $user->id)->update(['organisation_id' => $orgId]);
                // Animals on no farm yet (older records) stay with their owner
                DB::table('animals')->whereNull('holding_id')->where('owner_id', $user->id)->update(['organisation_id' => $orgId]);
            }
        }

        // Animals follow their farm
        DB::statement('
            UPDATE animals a JOIN holdings h ON h.id = a.holding_id
            SET a.organisation_id = h.organisation_id
        ');

        // Everyone else on a farm's team (managers, workers, vets) joins that farm's organisation
        DB::statement("
            INSERT IGNORE INTO organisation_members (id, organisation_id, user_id, role, created_at, updated_at)
            SELECT UUID(), h.organisation_id, fm.user_id, 'member', NOW(), NOW()
            FROM farm_members fm JOIN holdings h ON h.id = fm.holding_id
            WHERE h.organisation_id IS NOT NULL
        ");
    }

    public function down()
    {
        foreach (['animals', 'holdings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['organisation_id']);
                $table->dropColumn('organisation_id');
            });
        }
        Schema::dropIfExists('organisation_members');
        Schema::dropIfExists('organisations');
    }
};
