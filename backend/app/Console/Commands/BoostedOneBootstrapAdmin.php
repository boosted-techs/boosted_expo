<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
class BoostedOneBootstrapAdmin extends Command {
 protected $signature='boostedone:provision-admin {email} {--send-reset}';
 protected $description='Provision first administrator with randomized credentials and optional email setup';
 public function handle():int {
  $email=strtolower(trim($this->argument('email')));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)) {$this->error('Invalid email');return 1;}
  if(User::query()->where('is_platform_admin',true)->exists()) {$this->error('An administrator already exists. No permissions changed.');return 1;}
  if(User::query()->where('email',$email)->exists()) {$this->error('Account exists; no automatic privilege escalation.');return 1;}
  $user=User::create(['name'=>'Boosted One Administrator','email'=>$email,'password'=>Hash::make(Str::random(80)),'email_verified_at'=>now()]);
  $user->forceFill(['is_platform_admin'=>true])->save();
  if($this->option('send-reset')) {Password::sendResetLink(['email'=>$email]);$this->info('A password setup email was requested.');}
  $this->info('Administrator provisioned for '.$email.' (password not displayed).');
  return 0;
 }
}
