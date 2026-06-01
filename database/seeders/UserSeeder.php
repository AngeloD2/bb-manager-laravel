<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Always create the admin account
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
            ]
        );

        // Development-only accounts — not seeded in production
        if (app()->environment('local', 'testing')) {
            $devUsers = [
                [
                    'name' => 'Manager User',
                    'username' => 'manager',
                    'password' => Hash::make('password'),
                ],
                [
                    'name' => 'Operator User',
                    'username' => 'operator',
                    'password' => Hash::make('password'),
                ],
                [
                    'name' => 'Demo User',
                    'username' => 'demo',
                    'password' => Hash::make('password'),
                ],
            ];

            foreach ($devUsers as $userData) {
                User::firstOrCreate(
                    ['username' => $userData['username']],
                    [
                        'name' => $userData['name'],
                        'password' => $userData['password'],
                    ]
                );
            }
        }
    }
}
