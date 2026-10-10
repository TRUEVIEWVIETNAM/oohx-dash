<?php

namespace App\Services\ScreenImport;

use App\Models\Network;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Write 1 validated row vào DB: Screen + ScreenSpec + ScreenInventory (+ auto-create Site/Network nếu cần).
 *
 * Giả định: row đã qua RowValidator.
 *
 * Upsert mode:
 *   - 'skip':   nếu external_id tồn tại → không ghi, throw RowSkippedException
 *   - 'update': nếu external_id tồn tại → updateOrCreate (chỉ update fields có giá trị trong row)
 */
class ScreenWriter
{
    public function __construct(
        private readonly string $ownerId,
        private readonly string $upsertMode = 'skip',
    ) {}

    /**
     * @param array<string, array<string, mixed>> $data
     * @param RowValidator $validator
     *
     * @return array{screen_id: int|string, action: string}  action ∈ created|updated|skipped
     */
    public function write(array $data, RowValidator $validator): array
    {
        $screenData    = $data['screens']   ?? [];
        $specData      = $data['spec']      ?? [];
        $inventoryData = $data['inventory'] ?? [];
        $siteData      = $data['site']      ?? [];

        // 1. Resolve or create Site
        $siteId = $validator->resolveSiteId($data) ?? $this->createSite($siteData);

        // 2. Resolve/create Network (if inventory.network_name provided)
        $networkId = null;
        if (! empty($inventoryData['network_name'])) {
            $network = Network::firstOrCreate(
                ['owner_id' => $this->ownerId, 'name' => $inventoryData['network_name']],
                ['code' => Str::slug($inventoryData['network_name']), 'status' => Network::STATUS_ACTIVE]
            );
            $networkId = $network->id;
            Site::where('id', $siteId)->whereNull('network_id')->update(['network_id' => $networkId]);
        }

        // 3. Upsert Screen
        $externalId = $screenData['external_id'] ?? null;
        $existing = $externalId
            ? Screen::withoutGlobalScopes()->where('owner_id', $this->ownerId)->where('external_id', $externalId)->first()
            : null;

        if ($existing && $this->upsertMode === 'skip') {
            return ['screen_id' => $existing->id, 'action' => 'skipped'];
        }

        $screen = Screen::updateOrCreate(
            ['owner_id' => $this->ownerId, 'external_id' => $externalId],
            array_filter([
                'site_id'        => $siteId,
                'name'           => $screenData['name']           ?? null,
                'slug'           => Screen::generateUniqueSlug($screenData['name'] ?? $externalId),
                'description'    => $screenData['description']    ?? null,
                'status'         => $screenData['status']         ?? Screen::STATUS_OFFLINE,
                'active'         => $screenData['active']         ?? true,
                'placement_zone' => $screenData['placement_zone'] ?? null,
                'orientation'    => $screenData['orientation']    ?? null,
                'daily_footfall' => $screenData['daily_footfall'] ?? null,
                'monthly_reach'  => $screenData['monthly_reach']  ?? null,
            ], fn ($v) => $v !== null),
        );

        // 4. ScreenSpec
        if ($specData) {
            ScreenSpec::updateOrCreate(
                ['screen_id' => $screen->id],
                array_filter([
                    'width_px'          => $specData['width_px']          ?? 1920,
                    'height_px'         => $specData['height_px']         ?? 1080,
                    'width_cm'          => $specData['width_cm']          ?? null,
                    'height_cm'         => $specData['height_cm']         ?? null,
                    'resolution_preset' => $specData['resolution_preset'] ?? null,
                    'facing_direction'  => $specData['facing_direction']  ?? null,
                    'photo_url'         => $specData['photo_url']         ?? null,
                    'photos'            => ! empty($specData['photo_url']) ? [$specData['photo_url']] : null,
                    'allow_image'       => $specData['allow_image']       ?? true,
                    'allow_video'       => $specData['allow_video']       ?? true,
                    'allow_html'        => $specData['allow_html']        ?? false,
                    'allow_vast'        => true,
                ], fn ($v) => $v !== null),
            );
        }

        // 5. ScreenInventory
        $vnCatId = $validator->resolveVnCategoryId($data);
        $invPayload = array_filter([
            'network_id'           => $networkId,
            'network_name'         => $inventoryData['network_name']         ?? null,
            'vn_category_id'       => $vnCatId,
            'floor_cpm'            => $inventoryData['floor_cpm']            ?? null,
            'floor_cpm_currency'   => $inventoryData['floor_cpm_currency']   ?? 'VND',
            'io_rate'              => $inventoryData['io_rate']              ?? null,
            'io_rate_unit'         => $inventoryData['io_rate_unit']         ?? 'month',
            'pricing_model'        => $inventoryData['pricing_model']        ?? 'io',
            'weekly_impressions'   => $inventoryData['weekly_impressions']   ?? null,
            'spot_length'          => $inventoryData['spot_length']          ?? 15,
            'loop_length'          => $inventoryData['loop_length']          ?? null,
            'timezone'             => $inventoryData['timezone']             ?? 'Asia/Ho_Chi_Minh',
            'programmatic_enabled' => $inventoryData['programmatic_enabled'] ?? false,
        ], fn ($v) => $v !== null);

        if (! empty($invPayload['floor_cpm']) && ($invPayload['floor_cpm_currency'] ?? 'VND') === 'VND') {
            $invPayload['floor_cpm_usd'] = round($invPayload['floor_cpm'] / 25000, 4);
        }

        if (! empty($inventoryData['hours_open']) || ! empty($inventoryData['hours_close'])) {
            $oh = [];
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
                $oh[$d] = [
                    'open'  => $inventoryData['hours_open']  ?? '08:00',
                    'close' => $inventoryData['hours_close'] ?? '22:00',
                ];
            }
            $invPayload['operating_hours'] = $oh;
        }

        if ($invPayload) {
            ScreenInventory::updateOrCreate(['screen_id' => $screen->id], $invPayload);
        }

        return [
            'screen_id' => $screen->id,
            'action'    => $existing ? 'updated' : 'created',
        ];
    }

    private function createSite(array $siteData): int
    {
        $name = $siteData['name'] ?? null;
        if (! $name) {
            throw new \RuntimeException('Cannot create site — site.name required');
        }

        // Auto-derive a default network so Site.network_id is satisfied
        $networkId = Network::firstOrCreate(
            ['owner_id' => $this->ownerId, 'name' => 'Default'],
            ['code' => 'default', 'status' => Network::STATUS_ACTIVE]
        )->id;

        $site = Site::create([
            'owner_id'    => $this->ownerId,
            'network_id'  => $networkId,
            'external_id' => $siteData['external_id'] ?? Str::slug($name) . '-' . Str::random(4),
            'name'        => $name,
            'slug'        => Site::generateUniqueSlug($name),
            'address'     => $siteData['address'] ?? null,
            'city'        => $siteData['city']    ?? null,
            'lat'         => $siteData['lat']     ?? null,
            'lon'         => $siteData['lon']     ?? null,
            'status'      => Site::STATUS_ACTIVE,
            'country'     => 'VN',
        ]);
        return $site->id;
    }
}
