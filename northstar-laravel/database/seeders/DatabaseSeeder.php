<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\DemoWorkspace;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo account with a fully populated workspace.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@northstar.test'],
            ['name' => 'Jordan Davis', 'company' => 'Acme Co', 'password' => 'password'],
        );

        if (! $user->channels()->exists()) {
            DemoWorkspace::seed($user);
        }
    }
}
