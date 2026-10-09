<?php

namespace Database\Seeders;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\FarmMember;
use App\Models\MilkLog;
use App\Services\LactationBook;
use App\Services\MilkLedger;
use App\Support\FarmClock;
use App\Models\Holding;
use App\Models\OfficerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Demo accounts for testing the full flow. NOT part of DatabaseSeeder: run it on purpose,
//   php artisan db:seed --class=DemoSeeder --force
// and delete these users before real people use the system. Safe to re-run (updates in place).
// Password: DEMO_PASSWORD from .env, else "mycow-demo-2026".
class DemoSeeder extends Seeder
{
    public function run()
    {
        $this->call(CountySeeder::class);
        $password = Hash::make(env('DEMO_PASSWORD', 'mycow-demo-2026'));

        $admin = $this->user('admin@mycow.test', 'Demo Admin', User::ROLE_ADMIN, $password);

        $officer = $this->user('officer@mycow.test', 'Dr. Demo Officer', User::ROLE_OFFICER, $password, '0711000001');
        $profile = OfficerProfile::firstOrNew(['user_id' => $officer->id]);
        $profile->fill([
            'licence_number' => 'KVB/DEMO/0001',
            'cadre' => 'vet',
            'phone' => '0711000001',
            'county_id' => 32,              // Nakuru
            'organisation' => 'Demo practice',
        ]);
        $profile->user_id = $officer->id;
        $profile->forceFill(['status' => 'approved', 'verified_at' => now(), 'verified_by' => $admin->id])->save();

        $farmer = $this->user('farmer@mycow.test', 'Demo Farmer', User::ROLE_FARMER, $password, '0722000002');
        $farm = Holding::firstOrNew(['owner_id' => $farmer->id, 'name' => 'Demo farm']);
        $farm->fill([
            'county_id' => 32, 'sub_county' => 'Njoro', 'village' => 'Demo farm',
            'latitude' => -0.3302, 'longitude' => 35.9437, 'location_source' => 'map',   // near Njoro, Nakuru
        ]);
        $farm->owner_id = $farmer->id;
        $farm->save();

        $animal = Animal::withTrashed()->firstOrNew(['tag_number' => 'KE-DEMO-0001']);
        $animal->fill([
            'name' => 'Demo Daisy', 'sex' => 'female', 'age_estimate_months' => 30, 'colour' => 'Black and white',
            'purpose' => 'dairy', 'breed_claimed' => 'Holstein',
        ]);
        $animal->owner_id = $farmer->id;
        $animal->placeAt($farm);
        $animal->deleted_at = null;
        $animal->save();

        // A farm worker on the demo farm: signs in with phone + PIN on the "Farm worker" tab
        $worker = User::firstOrNew(['login_phone' => '+254733000003']);
        $worker->fill(['fullname' => 'Demo Worker', 'phone' => '+254733000003']);
        $worker->forceFill([
            'role' => User::ROLE_WORKER,
            'login_phone' => '+254733000003',
            'password' => Hash::make(env('DEMO_WORKER_PIN', '2580')),
        ])->save();
        $member = FarmMember::firstOrNew(['holding_id' => $farm->id, 'user_id' => $worker->id]);
        $member->forceFill(['holding_id' => $farm->id, 'user_id' => $worker->id, 'role' => FarmMember::WORKER])->save();

        // Daisy calved ~4 months ago and has two weeks of milkings, so the farm's charts have something to show
        if (!$animal->events()->where('type', 'calving')->exists()) {
            $calving = new AnimalEvent(['type' => 'calving', 'event_date' => FarmClock::now()->subDays(120)->toDateString(), 'details' => 'Heifer calf']);
            $calving->forceFill(['animal_id' => $animal->id, 'recorded_by' => $farmer->id])->save();
            app(LactationBook::class)->rebuild($animal);
        }
        if (!MilkLog::where('animal_id', $animal->id)->exists()) {
            $ledger = app(MilkLedger::class);
            for ($d = 14; $d >= 1; $d--) {
                $day = FarmClock::now()->subDays($d)->toDateString();
                $ledger->recordSession($farm, $day, 'am', [['animal_id' => $animal->id, 'litres' => 9 + ($d % 3) * 0.5]], $farmer);
                $ledger->recordSession($farm, $day, 'pm', [['animal_id' => $animal->id, 'litres' => 6.5 + ($d % 2)]], $farmer);
            }
        }

        $this->command?->info('Demo accounts ready: admin@ / officer@ / farmer@mycow.test (officer covers Nakuru).');
        $this->command?->info('Demo farm worker: phone 0733 000 003, PIN DEMO_WORKER_PIN or 2580.');
        $this->command?->warn('Add photos to "Demo Daisy" as the farmer before requesting certification. Remove demo users before going live.');
    }

    private function user(string $email, string $name, string $role, string $hash, ?string $phone = null): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill(['fullname' => $name, 'phone' => $phone]);
        $user->forceFill(['role' => $role, 'password' => $hash])->save();

        return $user;
    }
}
