<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ImpersonationManager
{
    private const SESSION_KEY = 'impersonation';

    public function active(): bool
    {
        return is_array(session()->get(self::SESSION_KEY));
    }

    public function actor(): ?User
    {
        if (! $this->active()) {
            return Auth::user();
        }

        $actorId = session()->get(self::SESSION_KEY.'.actor_id');

        return $actorId ? User::query()->find($actorId) : null;
    }

    public function effectiveUser(): ?User
    {
        return Auth::user();
    }

    public function target(): ?User
    {
        if (! $this->active()) {
            return null;
        }

        $targetId = session()->get(self::SESSION_KEY.'.target_id');

        return $targetId ? User::query()->find($targetId) : null;
    }

    public function startedAt(): ?int
    {
        return $this->active() ? (int) session()->get(self::SESSION_KEY.'.started_at') : null;
    }

    public function actorSessionIsCurrent(): bool
    {
        $actor = $this->actor();

        return $actor !== null
            && $actor->is_active
            && (int) session()->get(self::SESSION_KEY.'.actor_session_version', -1) === $actor->session_version
            && $actor->hasPermissionTo('users.impersonate');
    }

    public function expired(): bool
    {
        $startedAt = $this->startedAt();
        $maximumMinutes = max(1, (int) config('impersonation.max_minutes', 60));

        return $startedAt !== null && now()->timestamp - $startedAt >= $maximumMinutes * 60;
    }

    public function start(Request $request, User $target): void
    {
        abort_if($this->active(), 403, 'No se permite anidar impersonaciones.');

        $actor = $request->user();
        abort_unless($actor?->is_active && $actor->hasPermissionTo('users.impersonate'), 403);
        abort_if((int) $actor->id === (int) $target->id, 422, 'No puedes impersonar tu propia cuenta.');

        $startedAt = now()->timestamp;
        $request->session()->put(self::SESSION_KEY, [
            'actor_id' => (int) $actor->id,
            'actor_session_version' => $actor->session_version,
            'target_id' => (int) $target->id,
            'started_at' => $startedAt,
        ]);
        Auth::guard()->login($target, false);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $target->session_version);

        app(AdminAuditLogger::class)->record(
            'impersonation.started',
            'user',
            $target->id,
            null,
            ['target_name' => $target->name, 'target_email' => $target->email, 'started_at' => now()->toIso8601String()],
            [
                'impersonation' => $this->auditContext(),
                'user_agent' => $this->sanitizedUserAgent($request),
            ],
            (int) $actor->id,
        );
    }

    public function finish(Request $request, string $reason = 'manual', bool $restoreActor = true): ?User
    {
        if (! $this->active()) {
            return null;
        }

        $actorId = (int) session()->get(self::SESSION_KEY.'.actor_id');
        $target = $this->target();
        $actor = $actorId > 0 ? User::query()->find($actorId) : null;
        $context = $this->auditContext();
        $context['end_reason'] = $reason;

        app(AdminAuditLogger::class)->record(
            'impersonation.ended',
            'user',
            $target?->id ?? session()->get(self::SESSION_KEY.'.target_id'),
            null,
            ['ended_at' => now()->toIso8601String(), 'reason' => $reason],
            ['impersonation' => $context],
            $actorId > 0 ? $actorId : null,
        );

        $actorCanBeRestored = $actor !== null
            && $actor->is_active
            && (int) session()->get(self::SESSION_KEY.'.actor_session_version', -1) === $actor->session_version
            && $actor->hasPermissionTo('users.impersonate');

        $request->session()->forget(self::SESSION_KEY);

        if (! $restoreActor || ! $actorCanBeRestored) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard()->login($actor, false);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', $actor->session_version);

        return $actor;
    }

    /** @return array{active: bool, actor_id: int|null, target_id: int|null, started_at: string|null} */
    public function auditContext(): array
    {
        $startedAt = $this->startedAt();

        return [
            'active' => $startedAt !== null,
            'actor_id' => $this->active() ? (int) session()->get(self::SESSION_KEY.'.actor_id') : null,
            'target_id' => $this->active() ? (int) session()->get(self::SESSION_KEY.'.target_id') : null,
            'started_at' => $startedAt ? now()->setTimestamp($startedAt)->toIso8601String() : null,
        ];
    }

    public function sanitizedUserAgent(Request $request): ?string
    {
        $value = preg_replace('/[[:cntrl:]]/', '', (string) $request->userAgent());

        return $value === '' ? null : Str::limit($value, 500, '');
    }
}
