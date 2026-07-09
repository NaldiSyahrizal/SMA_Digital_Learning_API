<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class PrincipalSeeder extends Seeder
{
    public function run()
    {
        // 1. Ensure the role exists
        $roleId = DB::table('roles')->where('name', 'kepala_sekolah')->value('id');
        if (!$roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'kepala_sekolah',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Create the Principal User
        $user = User::firstOrCreate(
            ['email' => 'kepsek@sekolah.com'],
            [
                'username' => 'kepsek',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // 3. Assign Role
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }
}
