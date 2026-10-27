<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

// Schedules are opt-in until production Redis, encrypted backup key and an
// external scheduler are configured. They never run in local development.
if (config('expo_operations.backup_schedule') === true) {
    Schedule::command('expo:backup')->dailyAt('02:20')
        ->timezone('UTC')->onOneServer()->withoutOverlapping(180);
}
if (config('expo_operations.monitor_schedule') === true) {
    Schedule::command('expo:monitor')->everyFiveMinutes()
        ->onOneServer()->withoutOverlapping(10);
}

// Opt in explicitly when production scheduler and mail transport are ready.
if (config('expo_operations.billing_schedule') === true) {
    Schedule::command('boostedone:billing-cycle')->dailyAt('08:00')
        ->timezone('Africa/Kampala')->onOneServer()->withoutOverlapping(30);
}

Artisan::command('expo:bootstrap-admin', function () {
    if (User::query()->where('is_platform_admin', true)->exists()) {
        $this->error('A platform administrator already exists. Existing admin management requires an authorised process.');
        return 1;
    }

    $name = trim((string) $this->ask('Administrator name'));
    $email = strtolower(trim((string) $this->ask('Administrator email')));
    $password = (string) $this->secret('New password (at least 12 characters)');
    $confirmation = (string) $this->secret('Repeat password');

    if (strlen($name) < 2 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
        $this->error('Name, email or password is invalid, or passwords do not match.');
        return 1;
    }

    if (User::query()->where('email', $email)->exists()) {
        $this->error('That email is already registered. No privileges were changed.');
        return 1;
    }

    $admin = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
    ]);
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->info('Platform admin created. Sign in through /login.');
    return 0;
})->purpose('Securely provision the first Boosted Export ERP platform administrator');