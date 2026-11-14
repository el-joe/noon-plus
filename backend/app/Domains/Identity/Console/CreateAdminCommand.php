<?php

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('nawy:admin:create {email} {--name=Platform admin}')]
#[Description('Create (or promote) a platform admin with access to every country')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $password = (string) $this->secret('Password (min 12 characters)');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name'), 'password' => $password, 'email_verified_at' => now()],
        );
        $user->memberships()->firstOrCreate(['role' => MembershipRole::Admin, 'country_id' => null]);

        $this->components->info("Admin {$email} is ready");

        return self::SUCCESS;
    }
}
