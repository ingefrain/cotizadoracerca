<?php

namespace App\Providers;

use App\Contracts\OutboxEmailSender;
use App\Mail\LaravelOutboxEmailSender;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Document;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Policies\CatalogItemPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\LocationPolicy;
use App\Policies\OrganizationPolicy;
use App\Support\BasePermissions;
use App\Support\ImpersonationManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OutboxEmailSender::class, LaravelOutboxEmailSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Catalog::class, CatalogPolicy::class);
        Gate::policy(CatalogItem::class, CatalogItemPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            $impersonation = app(ImpersonationManager::class);
            $actor = $impersonation->active() ? $impersonation->actor() : null;

            return $actor?->hasPermissionTo($ability) ? true : null;
        });

        foreach (array_keys(BasePermissions::DEFINITIONS) as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermissionTo($permission));
        }

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => route('admin.password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]));
    }
}
