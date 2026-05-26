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
        $users = [
            [
                'name' => 'Admin User',
                'username' => 'admin',
                'password' => Hash::make('password'),
            ],
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

        foreach ($users as $userData) {
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
