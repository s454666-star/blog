<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $faker = Faker::create();

        // 產生 20 筆假資料
        for ($i = 0; $i < 20; $i++) {
            User::create([
                'username' => $faker->unique()->userName,
                'password' => Hash::make('password123'), // 為每個使用者設定相同的密碼
                'name' => $faker->name,
                'email' => $faker->unique()->safeEmail,
                'phone' => $faker->phoneNumber,
                'address' => $faker->address,
                'gender' => $faker->randomElement(['male', 'female', 'other']),
                'birthdate' => $faker->date(),
                'nationality' => $faker->country,
                'role' => $faker->randomElement(['admin', 'user', 'guest']),
                'status' => $faker->randomElement(['active', 'inactive', 'banned']),
                'email_verified' => $faker->boolean,
                'last_login' => $faker->dateTime,
            ]);
        }
    }
}
