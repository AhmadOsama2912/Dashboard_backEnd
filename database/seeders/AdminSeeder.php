<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admins')->updateOrInsert(
            ['email' => 'a.afsha2912@gmail.com'],
            [
                'name' => 'Super Admin',
                'username' => 'admin',
                'password' => Hash::make('Test@12345'), // change me
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
