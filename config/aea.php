<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Super Admin
    |--------------------------------------------------------------------------
    |
    | The one account seeded automatically so someone can log in and start
    | creating the rest of the staff accounts. Every other user is created
    | through the app itself, not seeded, see UserSeeder for why.
    |
    */

    'bootstrap_admin_name' => env('AEA_BOOTSTRAP_ADMIN_NAME', 'Super Admin'),
    'bootstrap_admin_email' => env('AEA_BOOTSTRAP_ADMIN_EMAIL'),
    'bootstrap_admin_password' => env('AEA_BOOTSTRAP_ADMIN_PASSWORD'),

];