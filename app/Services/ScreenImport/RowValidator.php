<?php

namespace App\Services\ScreenImport;

use App\Models\Screen;
use App\Models\Site;
use App\Models\VenueCategory;

/**
 * Validate 1 transformed row trước khi insert.
 *
 * Checks:
 *   - Required fields present
 *   - FK refs (site by external_id) resolvable
 *   - Enum values valid (already handled by RowTransformer but double-check)
 *   - GPS range
 *   - Dimension bounds (width_px/height_px > 0)
 *
 * Returns errors array; empty = valid row.
 */
class RowValidator
{
    private array $existingSiteIds;
    private array $existingScreenExternalIds;
    private array $vnCategoryMap;

    public function __construct(private readonly string $ownerId)
    {
        $this->existingSiteIds = Site::withoutGlobalScopes()
            ->where('owner_id', $ownerId)
            ->pluck('id', 'external_id')
            ->all();

        $this->existingScreenExternalIds = Screen::withoutGlobalScopes()
            ->where('owner_id', $ownerId)
            ->pluck('id', 'external_id')
            ->all();

        $this->vnCategoryMap = VenueCategory::where('is_active', true)
            ->pluck('id', 'name_vi')
            ->all();
    }

    /**
     * @param array<string, array<string, mixed>> $data  Output of RowTransformer::transform()[data]
     * @return list<string> errors — empty if valid
     */
    public function validate(array $data): array
    {
        $errors = [];

        $screens   = $data['screens']   ?? [];
        $spec      = $data['spec']      ?? [];
        $inventory = $data['inventory'] ?? [];
        $site      = $data['site']      ?? [];

        // Required: external_id + name
        if (empty($screens['external_id'])) {
            $errors[] = 'Thiếu mã screen (external_id)';
        }
        if (empty($screens['name'])) {
            $errors[] = 'Thiếu tên screen';
        }

        // Site resolution: need either existing site_external_id OR new site (name + city)
        $siteExtId = $site['external_id'] ?? null;
        $siteExists = $siteExtId && isset($this->existingSiteIds[$siteExtId]);
        $canCreateSite = ! empty($site['name']) && ! empty($site['city']);

        if (! $siteExists && ! $canCreateSite) {
            if ($siteExtId) {
                $errors[] = "Site [{$siteExtId}] không tồn tại và không đủ thông tin để tạo mới (cần site.name + site.city)";
            } else {
                $errors[] = 'Cần site.external_id (existing) hoặc site.name + site.city (tạo mới)';
            }
        }

        // Dimensions
        $wPx = $spec['width_px']  ?? null;
        $hPx = $spec['height_px'] ?? null;
        if ($wPx !== null && ($wPx <= 0 || $wPx > 20000)) {
            $errors[] = "width_px không hợp lệ: {$wPx}";
        }
        if ($hPx !== null && ($hPx <= 0 || $hPx > 20000)) {
            $errors[] = "height_px không hợp lệ: {$hPx}";
        }

        // GPS range
        if (isset($site['lat']) && ($site['lat'] < -90 || $site['lat'] > 90)) {
            $errors[] = "Latitude out of range: {$site['lat']}";
        }
        if (isset($site['lon']) && ($site['lon'] < -180 || $site['lon'] > 180)) {
            $errors[] = "Longitude out of range: {$site['lon']}";
        }

        // Floor CPM must be non-negative
        if (isset($inventory['floor_cpm']) && $inventory['floor_cpm'] < 0) {
            $errors[] = "Floor CPM âm: {$inventory['floor_cpm']}";
        }

        // VN category warning (not hard error — can still import)
        $vnCatLabel = $inventory['vn_category_name'] ?? null;
        if ($vnCatLabel && ! isset($this->vnCategoryMap[$vnCatLabel])) {
            $errors[] = "⚠ Venue category '{$vnCatLabel}' không khớp — sẽ để trống";
        }

        return $errors;
    }

    /**
     * `sites.id` là **ULID** (`char(26)`), không phải số tự tăng.
     *
     * Kiểu trả về từng khai `?int`, và PHP 8 không ép một ULID sang int — nó
     * **ném TypeError**. Nghĩa là hàm này đổ mỗi lần tra ra một địa điểm, tức
     * mỗi dòng nhập có mã điểm đặt đã tồn tại. Nó im lặng cho tới hôm nay vì
     * không ca test nào gọi `ScreenWriter` (20/10/2026).
     */
    public function resolveSiteId(array $data): ?string
    {
        $extId = $data['site']['external_id'] ?? null;
        return $extId ? ($this->existingSiteIds[$extId] ?? null) : null;
    }

    public function resolveVnCategoryId(array $data): ?int
    {
        $label = $data['inventory']['vn_category_name'] ?? null;
        return $label ? ($this->vnCategoryMap[$label] ?? null) : null;
    }

    public function screenExternalIdExists(string $externalId): bool
    {
        return isset($this->existingScreenExternalIds[$externalId]);
    }
}
