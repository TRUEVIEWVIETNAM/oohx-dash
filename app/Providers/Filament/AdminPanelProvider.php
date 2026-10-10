<?php
namespace App\Providers\Filament;

use App\Filament\Resources\OwnerResource;
use App\Filament\Resources\ScreenResource;
use App\Filament\Resources\SiteResource;
use App\Filament\Widgets\OohxHealthSummaryWidget;
use App\Filament\Widgets\RegistryStatsWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(app()->environment('local') ? null : config('domains.dash'))
            ->login(\Filament\Pages\Auth\Login::class)
            ->loginRouteSlug('login')
            ->colors(['primary' => Color::hex('#2A4FF6')]) // OOHX brand blue
            ->brandName('OOHX Admin')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Kho điểm phát')->icon('heroicon-o-squares-2x2'),
                NavigationGroup::make('Tổ chức')->icon('heroicon-o-building-office'),
                NavigationGroup::make('Cấu hình hệ thống')->icon('heroicon-o-cog-6-tooth'),
                NavigationGroup::make('Phân tích')->icon('heroicon-o-chart-bar'),
                NavigationGroup::make('Công cụ')->icon('heroicon-o-wrench-screwdriver'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->discoverWidgets(in: app_path('Filament/Shared/Widgets'), for: 'App\\Filament\\Shared\\Widgets')
            ->pages([Dashboard::class])
            ->widgets([RegistryStatsWidget::class, OohxHealthSummaryWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->renderHook(PanelsRenderHook::BODY_END, fn(): HtmlString => new HtmlString(self::tableResizerScript()))
            ->authMiddleware([Authenticate::class])
            ->authGuard('web');
    }

    private static function tableResizerScript(): string
    {
        return <<<'HTML'
<script>
(function(){
    var ATTR='data-col-resizer', MIN=60;

    function initResizer(table){
        if(table.querySelector('['+ATTR+']')) return;
        var ths=[...table.querySelectorAll('thead tr:first-child th')];
        if(!ths.length) return;

        // Snapshot pixel widths while table is still auto-layout
        var ws=ths.map(function(th){ return th.getBoundingClientRect().width; });
        if(ws.every(function(w){ return w===0; })) return;

        // Build <colgroup> — controls entire column width (header + all body cells)
        var cg=table.querySelector('colgroup');
        if(!cg){ cg=document.createElement('colgroup'); table.insertBefore(cg,table.firstChild); }
        cg.innerHTML='';
        var cols=ws.map(function(w){
            var col=document.createElement('col');
            col.style.width=w+'px';
            cg.appendChild(col);
            return col;
        });

        // Fix table width to pixel so it can grow wider than container (overflow-x scrolls)
        var total=ws.reduce(function(a,b){ return a+b; },0);
        table.style.tableLayout='fixed';
        table.style.width=total+'px';

        // Add resize handle to each <th>
        ths.forEach(function(th,i){
            if(getComputedStyle(th).position==='static') th.style.position='relative';
            var h=document.createElement('span');
            h.setAttribute(ATTR,'1');
            h.setAttribute('aria-hidden','true');
            h.style.cssText='position:absolute;right:0;top:0;bottom:0;width:5px;cursor:col-resize;z-index:20;background:transparent;transition:background 0.15s';
            h.addEventListener('mouseenter',function(){ h.style.background='rgba(99,102,241,0.35)'; });
            h.addEventListener('mouseleave',function(){ h.style.background='transparent'; });
            h.addEventListener('mousedown',function(e){
                e.preventDefault(); e.stopPropagation();
                var x0=e.clientX, w0=parseFloat(cols[i].style.width)||ws[i];
                h.style.background='rgba(99,102,241,0.55)';
                document.body.style.cursor='col-resize';
                document.body.style.userSelect='none';
                function mv(e){
                    var nw=Math.max(MIN, w0+e.clientX-x0);
                    cols[i].style.width=nw+'px';
                    // Expand table width so other columns don't shrink
                    var tot=[...cg.querySelectorAll('col')].reduce(function(s,c){ return s+parseFloat(c.style.width); },0);
                    table.style.width=tot+'px';
                }
                function up(){
                    h.style.background='transparent';
                    document.body.style.cursor='';
                    document.body.style.userSelect='';
                    document.removeEventListener('mousemove',mv);
                    document.removeEventListener('mouseup',up);
                }
                document.addEventListener('mousemove',mv);
                document.addEventListener('mouseup',up);
            });
            th.appendChild(h);
        });
    }

    function initAll(){
        var tables=[...document.querySelectorAll('.fi-ta-content table,.fi-ta-ctn table,table.fi-ta-table')];
        if(!tables.length) tables=[...document.querySelectorAll('[wire\\:id] table')];
        tables.filter(function(t){ return t.tHead&&t.tHead.rows.length; }).forEach(initResizer);
    }

    document.addEventListener('DOMContentLoaded',function(){ setTimeout(initAll,300); });
    document.addEventListener('livewire:navigated',function(){ setTimeout(initAll,300); });
    document.addEventListener('livewire:init',function(){
        Livewire.hook('commit',function(p){ p.succeed(function(){ setTimeout(initAll,100); }); });
    });
})();
</script>
HTML;
    }
}
