<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoginSecurityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $since = now()->subDay();
        $failedResults = [LoginAttempt::INVALID_CREDENTIALS, LoginAttempt::INACTIVE_ACCOUNT];

        $metrics = [
            'successes' => LoginAttempt::query()->where('result', LoginAttempt::SUCCESS)->where('created_at', '>=', $since)->count(),
            'failures' => LoginAttempt::query()->whereIn('result', $failedResults)->where('created_at', '>=', $since)->count(),
            'rate_limited' => LoginAttempt::query()->where('result', LoginAttempt::RATE_LIMITED)->where('created_at', '>=', $since)->count(),
            'repeated_accounts' => DB::query()->fromSub(
                LoginAttempt::query()
                    ->select('email')
                    ->whereIn('result', $failedResults)
                    ->whereNotNull('email')
                    ->where('created_at', '>=', $since)
                    ->groupBy('email')
                    ->havingRaw('COUNT(*) >= 5'),
                'repeated_accounts',
            )->count(),
        ];

        $result = $request->query('result');
        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $ip = mb_substr(trim((string) $request->query('ip')), 0, 45);

        $attempts = LoginAttempt::query()
            ->with('user:id,name,email,last_activity_at')
            ->when(in_array($result, LoginAttempt::RESULTS, true), fn ($query) => $query->where('result', $result))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('email', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->when($ip !== '', fn ($query) => $query->where('ip_address', 'like', "%{$ip}%"))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.login-security.index', [
            'attempts' => $attempts,
            'metrics' => $metrics,
            'filters' => ['result' => $result, 'search' => $search, 'ip' => $ip],
            'since' => $since,
        ]);
    }
}
