<?php

namespace Database\Seeders;

use App\Models\Animal;
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

        $this->command?->info('Demo accounts ready: admin@ / officer@ / farmer@mycow.test (officer covers Nakuru).');
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
