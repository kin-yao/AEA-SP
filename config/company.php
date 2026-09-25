<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AEA's own business details
    |--------------------------------------------------------------------------
    |
    | Everything here is AEA's side of a quotation or invoice, the "From"
    | block, not the customer's. Every value below is a placeholder, none
    | of these are AEA's real details, replace them before anything goes
    | out to a real customer.
    |
    */

    'name' => 'AEA Limited',
    'kra_pin' => 'P000000000A', // placeholder, replace with the real KRA PIN
    'po_box' => 'P.O. Box 00000-00100, Nairobi', // placeholder
    'phone' => '+254 700 000 000', // placeholder
    'email' => 'info@aealimited.com', // placeholder

    'bank' => [
        'name' => 'Bank name here',
        'account_name' => 'AEA Limited',
        'account_number' => '0000000000',
        'branch' => 'Branch name here',
        'swift' => 'XXXXXXXX',
    ],

    'default_payment_terms' => 'Payment due within 30 days of invoice date.',

    'signatory' => [
        'name' => 'Authorized signatory name',
        'title' => 'Title here',
    ],

];
