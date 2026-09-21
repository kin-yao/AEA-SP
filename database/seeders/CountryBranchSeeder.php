<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountryBranchSeeder extends Seeder
{
    public function run(): void
    {
        $geography = [
            'Kenya' => ['branches' => ['Nairobi', 'Mombasa', 'Kisumu']],
            'Uganda' => ['branches' => ['Uganda branch']],
            'Tanzania' => ['branches' => ['Tanzania branch']],
            'Rwanda' => ['branches' => ['Rwanda branch']],
        ];

        foreach ($geography as $name => $data) {
            $countryId = DB::table('countries')->insertGetId([
                'name' => $name,
                'currency_code' => 'KES',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($data['branches'] as $branchName) {
                DB::table('branches')->insert([
                    'country_id' => $countryId,
                    'name' => $branchName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}