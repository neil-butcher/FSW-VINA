<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LocalUser extends Command
{
    protected $signature = 'app:local-user {email} {--name=} {--admin : Grant administrator access}';

    protected $description = 'Create a local user or set an existing user password interactively';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();
        $name = $this->option('name') ?: $user?->name ?: $this->ask('Name');
        $password = $this->secret('Password (at least 12 characters)');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $email, $name, $password) {
            $roleId = $this->option('admin') ? Role::ADMINISTRATOR : ($user?->role_id ?? Role::USER);
            if (! Role::find($roleId)) {
                $role = new Role;
                $role->id = $roleId;
                $role->name = $roleId === Role::ADMINISTRATOR ? 'administrator' : 'user';
                $role->save();
            }
            $user ??= new User;
            $user->email = $email;
            $user->name = $name;
            $user->role_id = $roleId;
            $user->password = Hash::make($password);
            $user->remember_token = null;
            $user->save();
        });

        $this->info('Local login configured for '.$email.'.');

        return self::SUCCESS;
    }
}
