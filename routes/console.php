<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('pam:admin {email?} {--name=Administrator PAM}', function () {
    $email = $this->argument('email') ?: $this->ask('Email administrator');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Email tidak valid.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Email sudah digunakan.');

        return 1;
    }
    $role = Role::where('slug', 'admin')->first();
    if (! $role) {
        $this->error('Jalankan migrate dan db:seed dahulu.');

        return 1;
    }
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    if (strlen($password ?? '') < 12) {
        $this->error('Kata sandi minimal 12 karakter.');

        return 1;
    }
    User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role_id' => $role->id, 'active' => true]);
    $this->info('Administrator berhasil dibuat.');
})->purpose('Buat administrator produksi secara interaktif tanpa kata sandi tetap');
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('whatsapp:dispatch')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    $disk = Storage::disk('local');
    foreach ($disk->allFiles('exports') as $file) {
        if ($disk->lastModified($file) < now()->subHours(2)->timestamp) {
            $disk->delete($file);
        }
    }
})->name('pam:clean-expired-exports')->hourly()->withoutOverlapping();
