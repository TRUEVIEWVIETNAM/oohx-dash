<?php

namespace App\Providers;

use App\Models\Campaign;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Observers\ScreenInventoryObserver;
use App\Observers\ScreenObserver;
use App\Models\Network;
use App\Models\Site;
use App\Policies\CampaignPolicy;
use App\Policies\NetworkPolicy;
use App\Policies\OrganizationUserPolicy;
use App\Policies\OwnerPolicy;
use App\Policies\OwnerUserPolicy;
use App\Policies\ScreenPolicy;
use App\Policies\SitePolicy;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use App\Http\Responses\LoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Mọi đường sửa giá đều để lại phiên bản — xem ScreenInventoryObserver.
        ScreenInventory::observe(ScreenInventoryObserver::class);

        $this->registerRateLimiters();

        Gate::policy(Campaign::class, CampaignPolicy::class);
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

    /**
     * Giới hạn tần suất.
     *
     * Nhóm `api` là giới hạn nền cho mọi route /api/*. Đặt quá chặt thì nó chặn
     * player trước khi chạm giới hạn riêng của player, nên nền phải rộng hơn và
     * route cần giới hạn riêng phải gắn tường minh (Codex R10).
     *
     * Đã đăng nhập thì đếm theo người dùng, chưa đăng nhập mới đếm theo IP: đếm
     * thuần theo IP sẽ gộp mọi request render phía máy chủ của Next.js vào một
     * địa chỉ và chặn nhầm lẫn nhau.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(300)
            ->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by($r->ip()),
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')) . '|' . $r->ip()),
        ]);

        RateLimiter::for('token', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        // Player gửi dày nhưng đếm theo thiết bị, không theo IP: nhiều màn hình
        // dùng chung một đường truyền là chuyện bình thường.
        RateLimiter::for('player', fn (Request $r) => Limit::perMinute(600)
            ->by((string) ($r->input('screen_uuid') ?: $r->ip())));

        RateLimiter::for('geocode', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
    }
}
