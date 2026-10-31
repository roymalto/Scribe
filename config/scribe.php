<?php

return [
    // The local edition: installed on one Windows PC for a doctor's own use (installer/windows). A first-run screen
    // makes the doctor's account and clinic, two-factor sign-in is optional, and licences need no verification.
    'local' => env('SCRIBE_EDITION') === 'local',

    // The first administrator, created by the seeder only when there are no users yet
    'admin_email' => env('SCRIBE_ADMIN_EMAIL'),
    'admin_password' => env('SCRIBE_ADMIN_PASSWORD'),

    // Demo accounts made by php artisan scribe:demo (fictional clinics only); empty in production
    'demo_password' => env('SCRIBE_DEMO_PASSWORD'),

    // Where staff check a doctor's licence before marking it verified
    'prc_verification_url' => 'https://verification.prc.gov.ph/Verification',
];
