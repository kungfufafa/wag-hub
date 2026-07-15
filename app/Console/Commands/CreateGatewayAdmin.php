<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateGatewayAdmin extends Command
{
    protected $signature = 'gateway:create-admin
        {--name= : Nama administrator}
        {--email= : Alamat email administrator}
        {--password= : Kata sandi administrator (minimal 12 karakter)}';

    protected $description = 'Create the initial active Gateway Hub administrator';

    public function handle(): int
    {
        $input = [
            'name' => $this->option('name'),
            'email' => $this->option('email'),
            'password' => $this->option('password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        if (User::query()->where('email', $validated['email'])->exists()) {
            $this->error('Pengguna dengan email tersebut sudah ada. Tidak ada data yang diubah.');

            return self::FAILURE;
        }

        $administrator = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->info("Administrator Gateway Hub {$administrator->email} berhasil dibuat.");

        return self::SUCCESS;
    }
}
