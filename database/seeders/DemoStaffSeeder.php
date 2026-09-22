<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoStaffSeeder extends Seeder
{
    /**
     * NOT part of the normal seeding chain, deliberately. In production,
     * accounts are created through the app itself: Super Admin creates
     * ICT, ICT creates everyone else, confirmed and locked in earlier.
     *
     * This exists purely so local development has realistic staff to
     * build and test Livewire screens against, instead of one lonely
     * bootstrap admin. Run it explicitly when you want that:
     *
     *   php artisan db:seed --class=DemoStaffSeeder
     *
     * Never called from DatabaseSeeder::run(), and never should be.
     */
    public function run(): void
    {
        $nairobi = Branch::where('name', 'Nairobi')->first();
        $mombasa = Branch::where('name', 'Mombasa')->first();
        $kisumu = Branch::where('name', 'Kisumu')->first();
        $uganda = Branch::where('name', 'Uganda branch')->first();

        $staff = [
            ['name' => 'Daniel Kiprotich', 'email' => 'd.kiprotich@aealimited.com', 'role' => 'Manager', 'branch' => $nairobi],
            ['name' => 'Timothy Wanami', 'email' => 't.wanami@aealimited.com', 'role' => 'Supervisor', 'branch' => $nairobi],
            ['name' => 'Hilda Murage', 'email' => 'h.murage@aealimited.com', 'role' => 'Service Admin', 'branch' => $nairobi],
            ['name' => 'Michael Maneno', 'email' => 'm.maneno@aealimited.com', 'role' => 'Technician', 'branch' => $nairobi],
            ['name' => 'Samuel Njoroge', 'email' => 's.njoroge@aealimited.com', 'role' => 'Technician', 'branch' => $nairobi],
            ['name' => 'Faith Chebet', 'email' => 'f.chebet@aealimited.com', 'role' => 'Technician', 'branch' => $nairobi],
            ['name' => 'Dennis Omondi', 'email' => 'd.omondi@aealimited.com', 'role' => 'Technician', 'branch' => $nairobi],
            ['name' => 'Ali Hassan', 'email' => 'a.hassan@aealimited.com', 'role' => 'Technician', 'branch' => $mombasa],
            ['name' => 'Brian Otieno', 'email' => 'b.otieno@aealimited.com', 'role' => 'Technician', 'branch' => $kisumu],
            ['name' => 'Grace Namuli', 'email' => 'g.namuli@aealimited.com', 'role' => 'Technician', 'branch' => $uganda],
            ['name' => 'Winnie Achieng', 'email' => 'w.achieng@aealimited.com', 'role' => 'Finance', 'branch' => $nairobi],
        ];

        foreach ($staff as $person) {
            $user = User::firstOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => Hash::make('password'),
                    'branch_id' => $person['branch']->id,
                ]
            );

            $user->assignRole($person['role']);
        }
    }
}