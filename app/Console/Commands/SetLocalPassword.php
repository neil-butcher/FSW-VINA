<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetLocalPassword extends Command
{
    protected $signature = 'local:set-password {email} {--generate : Generate a random password}';

    protected $description = 'Set the password for an existing local development user';

    public function handle(): int
    {
        if (!config('hts-appteam.local_password_login')) {
            $this->error('Local password login is not enabled.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        if ($this->option('generate')) {
            $password = bin2hex(random_bytes(16));
        } else {
            $password = $this->secret('New password (at least 12 characters)');
            if (!is_string($password) || strlen($password) < 12) {
                $this->error('The password must contain at least 12 characters.');

                return self::FAILURE;
            }
            if ($password !== $this->secret('Confirm password')) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $user->password = Hash::make($password);
        $user->save();
        $this->info('Password updated for '.$user->email.'.');

        if ($this->option('generate')) {
            $this->line('Generated password (shown only once): '.$password);
        }

        return self::SUCCESS;
    }
}
