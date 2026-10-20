<?php

namespace App\Services;

use App\Models\Network;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\VenueCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import Sites + Screens từ OOHX template Excel.
 *
 * Sheet "Sites":
 *   Row 1-3: Title + subtitle
 *   Row 4: Headers
 *   Row 5+: Data
 *   Cols: A=Mã site, B=Tên site, C=Địa chỉ, D=Thành phố, E=Lat, F=Lon, G=Mô tả
 *
 * Sheet "Screens":
 *   Row 1-2: Title + subtitle
 *   Row 3: Group headers
 *   Row 4: Column headers
 *   Row 5+: Data
 *   Cols: A=Mã screen, B=Tên screen, C=Mã site, D=Loại biển, E=Network,
 *         F=Rộng px, G=Cao px, H=Rộng cm, I=Cao cm, J=Giá VND,
 *         K=Lượt xem/tuần, L=Thời lượng QC, M=Giờ mở, N=Giờ đóng
 */
class SiteImportService
{
    private string $ownerId;
    private array $vnCategoryMap = [];

    public function __construct()
    {
        $this->vnCategoryMap = VenueCategory::where('is_active', true)
            ->pluck('id', 'name_vi')
            ->all();
    }

    /**
     * Preview import — dry run, returns summary + row details.
     */
    public function readPreview(string $filePath, string $ownerId): array
    {
        $this->ownerId = $ownerId;
        $spreadsheet = IOFactory::load($filePath);

        $sites   = $this->parseSitesSheet($spreadsheet);
        $screens = $this->parseScreensSheet($spreadsheet, $sites);

        return [
            'sites'   => $sites,
            'screens' => $screens,
            'summary' => [
                'sites_new'    => collect($sites)->where('action', 'new')->count(),
                'sites_update' => collect($sites)->where('action', 'update')->count(),
                'sites_error'  => collect($sites)->where('action', 'error')->count(),
                'screens_new'    => collect($screens)->where('action', 'new')->count(),
                'screens_update' => collect($screens)->where('action', 'update')->count(),
                'screens_error'  => collect($screens)->where('action', 'error')->count(),
            ],
        ];
    }

    /**
     * Execute import — writes to DB.
     */
    public function import(string $filePath, string $ownerId): array
    {
        $this->ownerId = $ownerId;
        $spreadsheet = IOFactory::load($filePath);

        $sitesData   = $this->parseSitesSheet($spreadsheet);
        $screensData = $this->parseScreensSheet($spreadsheet, $sitesData);

        $results = [
            'sites_created'   => 0,
            'sites_updated'   => 0,
            'screens_created' => 0,
            'screens_updated' => 0,
            'errors'          => [],

            // Màn hình bị lượt nhập này chuyển sang điểm đặt khác. Không phải
            // lỗi, nhưng là thứ người bấm "Nhập" phải đọc.
            'doi_diem_dat'    => [],
        ];

        // Pre-populate siteIdMap from existing DB sites (for screens referencing sites not in this file)
        $siteIdMap = Site::where('owner_id', $ownerId)->pluck('id', 'external_id')->all();

        // Import sites
        foreach ($sitesData as $s) {
            if ($s['action'] === 'error') {
                $results['errors'][] = "Site dòng {$s['row']} [{$s['external_id']}]: {$s['error']}";
                continue;
            }

            $site = Site::updateOrCreate(
                ['owner_id' => $ownerId, 'external_id' => $s['external_id']],
                [
                    'name'        => $s['name'],
                    'slug'        => Site::generateUniqueSlug($s['name']),
                    'address'     => $s['address'],
                    'city'        => $s['city'],
                    'lat'         => $s['lat'],
                    'lon'         => $s['lon'],
                    'description' => $s['description'],
                    'status'      => 'active',
                    'country'     => 'VN',
                ]
            );

            $siteIdMap[$s['external_id']] = $site->id;
            $s['action'] === 'new' ? $results['sites_created']++ : $results['sites_updated']++;
        }

        // Import screens
        foreach ($screensData as $sc) {
            if ($sc['action'] === 'error') {
                $results['errors'][] = "Screen dòng {$sc['row']} [{$sc['external_id']}]: {$sc['error']}";
                continue;
            }

            $siteId = $siteIdMap[$sc['site_external_id']] ?? null;
            if (! $siteId) {
                $results['errors'][] = "Screen dòng {$sc['row']} [{$sc['external_id']}]: Site [{$sc['site_external_id']}] không tìm thấy trong DB";
                continue;
            }

            // Resolve network + link to site
            $networkId = null;
            if (! empty($sc['network_name'])) {
                $network = Network::firstOrCreate(
                    ['owner_id' => $ownerId, 'name' => $sc['network_name']],
                    ['code' => Str::slug($sc['network_name']), 'status' => 'active']
                );
                $networkId = $network->id;

                // Link site to network if not yet set
                Site::where('id', $siteId)->whereNull('network_id')->update(['network_id' => $networkId]);
            }

            // Điểm đặt bản ghi đang gắn vào TRƯỚC lượt nhập này. Đọc trước
            // `updateOrCreate` vì sau đó không còn gì để so.
            $truocDo = Screen::withoutGlobalScopes()
                ->where('owner_id', $ownerId)
                ->where('external_id', $sc['external_id'])
                ->value('site_id');

            $screen = Screen::updateOrCreate(
                ['owner_id' => $ownerId, 'external_id' => $sc['external_id']],
                [
                    'site_id' => $siteId,

                    // Mã điểm đặt **do tệp nói**, lưu nguyên văn. Trước đây
                    // chỉ dùng để tra `site_id` rồi bỏ đi, nên không còn gì
                    // đối chiếu khi phép tra đi chệch —
                    // `SoatViTriService::ghepLechKhoa()` có 0/104 bản ghi
                    // kiểm được. Lưu nó lại là đủ để phép đo đó chạy thật.
                    'site_external_id' => $sc['site_external_id'],

                    'name'    => $sc['name'],
                    'slug'    => Screen::generateUniqueSlug($sc['name']),
                    'active'  => true,
                    'status'  => 'offline',
                ]
            );

            // Lượt nhập vừa chuyển một màn hình sang điểm đặt khác. Hợp lệ —
            // owner sửa tệp là được phép — nhưng đây cũng là đúng cái cách một
            // bản ghi đi sai chỗ, nên nó phải hiện trong báo cáo thay vì xảy
            // ra im lặng.
            if ($truocDo !== null && (string) $truocDo !== (string) $siteId) {
                $cu = Site::withoutGlobalScopes()->find($truocDo, ['external_id', 'name']);

                $results['doi_diem_dat'][] = [
                    'screen'   => $sc['external_id'],
                    'ten'      => $sc['name'],
                    'tu'       => $cu ? "{$cu->name} [{$cu->external_id}]" : (string) $truocDo,
                    'sang'     => "{$sc['site_external_id']}",
                ];

                Log::info('Nhập Excel chuyển màn hình sang điểm đặt khác', [
                    'owner_id'    => $ownerId,
                    'screen'      => $sc['external_id'],
                    'site_cu'     => $cu?->external_id,
                    'site_moi'    => $sc['site_external_id'],
                ]);
            }

            // Spec
            ScreenSpec::updateOrCreate(
                ['screen_id' => $screen->id],
                [
                    'width_px'    => $sc['width_px'],
                    'height_px'   => $sc['height_px'],
                    'width_cm'    => $sc['width_cm'],
                    'height_cm'   => $sc['height_cm'],
                    'allow_image' => true,
                    'allow_video' => true,
                ]
            );

            // Inventory
            $invData = [
                'network_id'        => $networkId,
                'network_name'      => $sc['network_name'],
                'vn_category_id'    => $sc['vn_category_id'],
                'floor_cpm'         => $sc['floor_cpm'],
                'floor_cpm_currency' => 'VND',
                'weekly_impressions' => $sc['weekly_impressions'],
                'spot_length'       => $sc['spot_length'] ?: 15,
                'timezone'          => 'Asia/Ho_Chi_Minh',
            ];

            if ($sc['floor_cpm']) {
                $invData['floor_cpm_usd'] = round($sc['floor_cpm'] / 25000, 4);
            }

            if ($sc['hours_open'] || $sc['hours_close']) {
                $oh = [];
                $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
                foreach ($days as $d) {
                    $oh[$d] = [
                        'open'  => $sc['hours_open'] ?: '08:00',
                        'close' => $sc['hours_close'] ?: '22:00',
                    ];
                }
                $invData['operating_hours'] = $oh;
            }

            ScreenInventory::updateOrCreate(
                ['screen_id' => $screen->id],
                $invData
            );

            $sc['action'] === 'new' ? $results['screens_created']++ : $results['screens_updated']++;
        }

        return $results;
    }

    // ── Parsers ──────────────────────────────────────────────────────────────

    private function parseSitesSheet($spreadsheet): array
    {
        // Try by name first, then fallback to first sheet
        $sheet = $spreadsheet->getSheetByName('Sites')
              ?? $spreadsheet->getSheet(0);
        if (! $sheet) {
            return [];
        }

        $rows  = $sheet->toArray(null, true, true, false);
        $sites = [];
        $seenIds = [];

        // Data starts at row 5 (index 4)
        for ($i = 4; $i < count($rows); $i++) {
            $row = $rows[$i];
            $extId = trim($row[0] ?? '');
            $name  = trim($row[1] ?? '');

            if ($extId === '' && $name === '') {
                continue; // Empty row
            }

            // Skip header row if accidentally included in data range
            if (strtolower($extId) === 'mã site' || str_starts_with(strtolower($extId), 'mã site')) {
                continue;
            }

            $entry = [
                'row'         => $i + 1,
                'external_id' => $extId,
                'name'        => $name,
                'address'     => trim($row[2] ?? ''),
                'city'        => trim($row[3] ?? ''),
                'lat'         => $this->parseNum($row[4] ?? null),
                'lon'         => $this->parseNum($row[5] ?? null),
                'description' => trim($row[6] ?? ''),
                'action'      => 'new',
                'error'       => null,
            ];

            // Validation
            if ($extId === '') {
                $entry['action'] = 'error';
                $entry['error']  = 'Mã site không được để trống';
            } elseif ($name === '') {
                $entry['action'] = 'error';
                $entry['error']  = 'Tên site không được để trống';
            } elseif (isset($seenIds[$extId])) {
                $entry['action'] = 'error';
                $entry['error']  = "Trùng mã site với dòng {$seenIds[$extId]}";
            } else {
                // Check DB
                $exists = Site::where('owner_id', $this->ownerId)->where('external_id', $extId)->exists();
                $entry['action'] = $exists ? 'update' : 'new';
                $seenIds[$extId] = $i + 1;
            }

            // Validate GPS
            if ($entry['lat'] !== null && ($entry['lat'] < -90 || $entry['lat'] > 90)) {
                $entry['action'] = 'error';
                $entry['error']  = 'Latitude phải từ -90 đến 90';
            }
            if ($entry['lon'] !== null && ($entry['lon'] < -180 || $entry['lon'] > 180)) {
                $entry['action'] = 'error';
                $entry['error']  = 'Longitude phải từ -180 đến 180';
            }

            $sites[] = $entry;
        }

        return $sites;
    }

    private function parseScreensSheet($spreadsheet, array $sitesData): array
    {
        // Try by name first, then fallback to second sheet
        $sheet = $spreadsheet->getSheetByName('Screens');
        if (! $sheet && $spreadsheet->getSheetCount() >= 2) {
            $sheet = $spreadsheet->getSheet(1);
        }
        if (! $sheet) {
            return [];
        }

        $rows    = $sheet->toArray(null, true, true, false);
        $screens = [];
        $seenIds = [];

        // Build valid site IDs from parsed sites
        $validSiteIds = collect($sitesData)
            ->where('action', '!=', 'error')
            ->pluck('external_id')
            ->flip()
            ->all();

        // Also check DB for existing sites
        $dbSiteIds = Site::where('owner_id', $this->ownerId)->pluck('external_id')->flip()->all();

        // Data starts at row 5 (index 4)
        for ($i = 4; $i < count($rows); $i++) {
            $row = $rows[$i];
            $extId       = trim($row[0] ?? '');
            $name        = trim($row[1] ?? '');
            $siteExtId   = trim($row[2] ?? '');

            if ($extId === '' && $name === '') {
                continue; // Empty row
            }

            // Skip header row if accidentally included in data range
            if (strtolower($extId) === 'mã screen' || str_starts_with(strtolower($extId), 'mã screen')) {
                continue;
            }

            $venueLabel = trim($row[3] ?? '');
            $vnCatId    = $this->vnCategoryMap[$venueLabel] ?? null;

            $entry = [
                'row'              => $i + 1,
                'external_id'      => $extId,
                'name'             => $name,
                'site_external_id' => $siteExtId,
                'vn_category_id'   => $vnCatId,
                'venue_label'      => $venueLabel,
                'network_name'     => trim($row[4] ?? ''),
                'width_px'         => $this->parseInt($row[5] ?? null) ?: 1920,
                'height_px'        => $this->parseInt($row[6] ?? null) ?: 1080,
                'width_cm'         => $this->parseNum($row[7] ?? null),
                'height_cm'        => $this->parseNum($row[8] ?? null),
                'floor_cpm'        => $this->parseNum($row[9] ?? null),
                'weekly_impressions' => $this->parseInt($row[10] ?? null),
                'spot_length'      => $this->parseInt($row[11] ?? null),
                'hours_open'       => $this->parseTime($row[12] ?? null),
                'hours_close'      => $this->parseTime($row[13] ?? null),
                'action'           => 'new',
                'error'            => null,
            ];

            // Validation
            if ($extId === '') {
                $entry['action'] = 'error';
                $entry['error']  = 'Mã screen không được để trống';
            } elseif ($name === '') {
                $entry['action'] = 'error';
                $entry['error']  = 'Tên screen không được để trống';
            } elseif ($siteExtId === '') {
                $entry['action'] = 'error';
                $entry['error']  = 'Mã site không được để trống';
            } elseif (! isset($validSiteIds[$siteExtId]) && ! isset($dbSiteIds[$siteExtId])) {
                $entry['action'] = 'error';
                $entry['error']  = "Mã site [{$siteExtId}] không tồn tại trong sheet Sites hoặc DB";
            } elseif (isset($seenIds[$extId])) {
                $entry['action'] = 'error';
                $entry['error']  = "Trùng mã screen với dòng {$seenIds[$extId]}";
            } else {
                $exists = Screen::where('owner_id', $this->ownerId)->where('external_id', $extId)->exists();
                $entry['action'] = $exists ? 'update' : 'new';
                $seenIds[$extId] = $i + 1;
            }

            // Warn if venue label doesn't match any VN category
            if ($venueLabel !== '' && $vnCatId === null && $entry['action'] !== 'error') {
                $entry['error'] = "Loại biển [{$venueLabel}] không khớp danh mục VN — sẽ để trống";
            }

            $screens[] = $entry;
        }

        return $screens;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function parseNum($val): ?float
    {
        if ($val === null || $val === '') {
            return null;
        }
        $val = str_replace(',', '', (string) $val);

        return is_numeric($val) ? (float) $val : null;
    }

    private function parseInt($val): ?int
    {
        $num = $this->parseNum($val);

        return $num !== null ? (int) $num : null;
    }

    private function parseTime($val): ?string
    {
        if ($val === null || trim((string) $val) === '') {
            return null;
        }
        $val = trim((string) $val);

        // Already HH:MM format
        if (preg_match('/^\d{1,2}:\d{2}$/', $val)) {
            return str_pad(explode(':', $val)[0], 2, '0', STR_PAD_LEFT) . ':' . explode(':', $val)[1];
        }

        // Just a number (hour)
        if (is_numeric($val)) {
            return str_pad((int) $val, 2, '0', STR_PAD_LEFT) . ':00';
        }

        return null;
    }
}
