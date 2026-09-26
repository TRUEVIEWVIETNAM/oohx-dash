<?php

namespace App\Providers;

use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Observers\ScreenObserver;
use App\Models\Network;
use App\Models\Site;
use App\Policies\NetworkPolicy;
use App\Policies\OrganizationUserPolicy;
use App\Policies\OwnerPolicy;
use App\Policies\OwnerUserPolicy;
use App\Policies\ScreenPolicy;
use App\Policies\SitePolicy;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use App\Http\Responses\LoginResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        Screen::observe(ScreenObserver::class);

        Gate::policy(Owner::class, OwnerPolicy::class);
        Gate::policy(OwnerUser::class, OwnerUserPolicy::class);
        Gate::policy(OrganizationUser::class, OrganizationUserPolicy::class);
        // Đăng ký tường minh thay vì dựa vào auto-discovery cho dễ truy vết.
        // (Ghi chú cũ nói thiếu đăng ký thì Gate "cho qua âm thầm" là SAI —
        // Laravel mặc định từ chối khi không tìm thấy policy hay ability.)
        Gate::policy(Screen::class, ScreenPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Network::class, NetworkPolicy::class);

        // Fix Livewire upload CORS: set APP_URL to match current request domain
        // so Livewire uploads go to the same origin (oohx.test or dash.oohx.test)
        if ($this->app->runningInConsole() === false && request()->getHost()) {
            $scheme = request()->getScheme();
            $host = request()->getHost();
            $port = request()->getPort();
            $url = $scheme . '://' . $host;
            if ($port && $port !== 80 && $port !== 443) {
                $url .= ':' . $port;
            }
            config([
                'app.url' => $url,
                'filesystems.disks.public.url' => $url . '/storage',
            ]);
            url()->forceRootUrl($url);

            if ($scheme === 'https') {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        }
    }
}
