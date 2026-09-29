<?php

namespace App\Filament\Shared\Pages;

use App\Filament\Shared\Widgets\NetworkDetailStats;
use App\Models\Screen;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Shared base for admin and publisher ViewNetwork pages.
 * Subclasses only need to declare $resource and optionally override screenViewUrl().
 */
abstract class BaseViewNetwork extends ViewRecord
{
    public array $screensMapData = [];
    public int   $totalScreens   = 0;
    public int   $totalSites     = 0;

    public function getTitle(): string
    {
        return 'Overview';
    }

    public function getView(): string
    {
        return 'filament.resources.network-resource.pages.view-network';
    }

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $network = $this->record;
        $network->loadMissing(['owner']);

        $this->totalScreens = Screen::whereHas('site', fn($q) => $q->where('network_id', $network->id))->count();
        $this->totalSites   = Screen::whereHas('site', fn($q) => $q->where('network_id', $network->id))
            ->distinct('site_id')->count('site_id');

        $this->screensMapData = $this->buildScreensMapData();
    }

    protected function buildScreensMapData(): array
    {
        $network = $this->record;
        $screens = Screen::whereHas('site', fn($q) => $q->where('network_id', $network->id))
            ->with(['site.province', 'site.commune'])
            ->get();

        return $screens->map(fn($s) => [
            'id'            => $s->id,
            'external_id'   => $s->external_id ?? '—',
            'name'          => $s->name ?? '—',
            'site_id'       => (string) ($s->site_id ?? ''),
            'site_name'     => $s->site?->name ?? '—',
            'site_lat'      => $s->site?->lat  ? (float) $s->site->lat  : null,
            'site_lon'      => $s->site?->lon  ? (float) $s->site->lon  : null,
            'province_id'   => $s->site?->province_id ? (string) $s->site->province_id : '',
            'province_name' => $s->site?->province?->name ?? '',
            'commune_id'    => $s->site?->commune_id ? (string) $s->site->commune_id : '',
            'commune_name'  => $s->site?->commune?->full_name ?? '',
            'active'        => (bool) $s->active,
            'view_url'      => $this->screenViewUrl($s),
        ])->values()->all();
    }

    /**
     * Hook: override in publisher subclass to point at publisher ScreenResource routes.
     */
    protected function screenViewUrl(Screen $screen): string
    {
        return \App\Filament\Resources\ScreenResource::getUrl('view', ['record' => $screen->id]);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return [NetworkDetailStats::class];
    }

    protected function getHeaderWidgetsData(): array
    {
        return ['record' => $this->record];
    }
}
