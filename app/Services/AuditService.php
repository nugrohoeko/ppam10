<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    public static function record(string $action, ?Model $subject = null, ?array $before = [], ?array $after = [], ?string $reason = null, ?User $actor = null): void
    {
        $sanitize = static function (?array $data): ?array {
            if ($data === null) {
                return null;
            }
            foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'access_token', 'app_secret', 'verify_token'] as $key) {
                unset($data[$key]);
            }

            return $data;
        };
        AuditLog::create([
            'user_id' => $actor?->id ?? Auth::id(), 'action' => $action, 'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(), 'before' => $sanitize($before), 'after' => $sanitize($after),
            'reason' => $reason, 'ip_address' => app()->runningInConsole() ? null : request()->ip(), 'created_at' => now(),
        ]);
    }
}
