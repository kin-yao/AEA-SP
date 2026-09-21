<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seeds exactly one account: the initial Super Admin, the only user who
     * has to exist before anyone can log in. Every other account (ICT,
     * Manager, Supervisor, Service Admin, Technicians, Finance, Customers)
     * is created through the app itself, not seeded:
     *
     *   Super Admin logs in -> creates the ICT account
     *   ICT logs in -> creates everyone else
     *
     * Credentials come from .env so nothing real is hardcoded here.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => config('aea.bootstrap_admin_email')],
            [
                'name' => config('aea.bootstrap_admin_name'),
                'password' => Hash::make(config('aea.bootstrap_admin_password')),
                'branch_id' => null,
                'status' => 'Active',
            ]
        )->assignRole('Super Admin');
    }
}