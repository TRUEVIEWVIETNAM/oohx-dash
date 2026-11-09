<?php

namespace App\Services\ScreenImport;

/**
 * Single source of truth for importable Screen fields.
 *
 * Each entry: [ key, group, label, type, required, description, enum?, unit? ]
 *
 * - `key` uses dotted notation matching RowTransformer output:
 *      screens.name            → Screen::$name
 *      spec.width_px           → ScreenSpec::$width_px
 *      inventory.floor_cpm     → ScreenInventory::$floor_cpm
 *      site.external_id        → Site lookup key (match existing site by external_id)
 *      site.name, site.lat...  → Site create-on-miss fallback fields
 *
 * Used by:
 *   - ColumnMappingAiService (injected into LLM prompt as schema)
 *   - Filament mapping UI (grouped dropdown options)
 *   - RowTransformer (route value into correct model's data array)
 *   - RowValidator (look up type/enum/required for per-cell validation)
 */
class FieldCatalog
{
    public const GROUP_SCREEN    = 'Screen';
    public const GROUP_SPEC      = 'Technical Spec';
    public const GROUP_INVENTORY = 'Inventory / Pricing';
    public const GROUP_SITE      = 'Site / Location';

    /**
     * @return list<array{
     *   key: string, group: string, label: string, type: string,
     *   required?: bool, description?: string, enum?: list<string>, unit?: string
     * }>
     */
    public static function all(): array
    {
        return [
            // ── Screen core ────────────────────────────────────────
            ['key' => 'screens.external_id',    'group' => self::GROUP_SCREEN, 'label' => 'External ID (Mã screen)', 'type' => 'string', 'required' => true,  'description' => 'Unique ID per owner. Used for upsert key.'],
            ['key' => 'screens.name',           'group' => self::GROUP_SCREEN, 'label' => 'Name (Tên màn hình)',     'type' => 'string', 'required' => true],
            ['key' => 'screens.description',    'group' => self::GROUP_SCREEN, 'label' => 'Description',              'type' => 'text'],
            ['key' => 'screens.status',         'group' => self::GROUP_SCREEN, 'label' => 'Status',                   'type' => 'enum',   'enum' => ['online', 'offline', 'maintenance']],
            ['key' => 'screens.active',         'group' => self::GROUP_SCREEN, 'label' => 'Active',                   'type' => 'bool'],
            ['key' => 'screens.placement_zone', 'group' => self::GROUP_SCREEN, 'label' => 'Placement zone',           'type' => 'enum',   'enum' => ['entrance', 'checkout', 'escalator', 'food_court', 'facade', 'lobby', 'parking', 'other']],
            ['key' => 'screens.orientation',    'group' => self::GROUP_SCREEN, 'label' => 'Orientation',              'type' => 'enum',   'enum' => ['landscape', 'portrait', 'square']],
            ['key' => 'screens.daily_footfall', 'group' => self::GROUP_SCREEN, 'label' => 'Daily footfall',           'type' => 'int'],
            ['key' => 'screens.monthly_reach',  'group' => self::GROUP_SCREEN, 'label' => 'Monthly reach',            'type' => 'int'],

            // ── Spec ───────────────────────────────────────────────
            ['key' => 'spec.width_px',          'group' => self::GROUP_SPEC, 'label' => 'Width (px)',        'type' => 'int',    'required' => true, 'unit' => 'px'],
            ['key' => 'spec.height_px',         'group' => self::GROUP_SPEC, 'label' => 'Height (px)',       'type' => 'int',    'required' => true, 'unit' => 'px'],
            ['key' => 'spec.width_cm',          'group' => self::GROUP_SPEC, 'label' => 'Width (cm)',        'type' => 'float',  'unit' => 'cm'],
            ['key' => 'spec.height_cm',         'group' => self::GROUP_SPEC, 'label' => 'Height (cm)',       'type' => 'float',  'unit' => 'cm'],
            // Công nghệ màn hình — lát 1 của `docs/PLAN-CONG-NGHE-MAN-HINH.md`.
            // KHÔNG `required`: 104 vị trí hiện có chưa khai, và một cột bắt
            // buộc sẽ làm mọi tệp nhập cũ đỏ toàn bộ. Để trống ⇒ `null` ⇒
            // "chưa khai", đúng trạng thái thật.
            ['key' => 'spec.cong_nghe',         'group' => self::GROUP_SPEC, 'label' => 'Công nghệ (led/lcd/in_bat)', 'type' => 'enum', 'enum' => ['led', 'lcd', 'in_bat'], 'description' => 'LED, LCD hoặc in bạt. Để trống nếu chưa chắc.'],
            ['key' => 'spec.resolution_preset', 'group' => self::GROUP_SPEC, 'label' => 'Resolution preset', 'type' => 'string', 'description' => 'E.g. "1920x1080", "4K", "Full HD"'],
            ['key' => 'spec.facing_direction',  'group' => self::GROUP_SPEC, 'label' => 'Facing direction',  'type' => 'enum',   'enum' => ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW']],
            ['key' => 'spec.photo_url',         'group' => self::GROUP_SPEC, 'label' => 'Photo URL',         'type' => 'string'],
            ['key' => 'spec.allow_image',       'group' => self::GROUP_SPEC, 'label' => 'Allow image',       'type' => 'bool'],
            ['key' => 'spec.allow_video',       'group' => self::GROUP_SPEC, 'label' => 'Allow video',       'type' => 'bool'],
            ['key' => 'spec.allow_html',        'group' => self::GROUP_SPEC, 'label' => 'Allow HTML',        'type' => 'bool'],

            // ── Inventory / Pricing ────────────────────────────────
            ['key' => 'inventory.floor_cpm',          'group' => self::GROUP_INVENTORY, 'label' => 'Floor CPM',             'type' => 'float',  'unit' => 'VND'],
            ['key' => 'inventory.floor_cpm_currency', 'group' => self::GROUP_INVENTORY, 'label' => 'Currency',              'type' => 'enum',   'enum' => ['VND', 'USD']],
            ['key' => 'inventory.io_rate',            'group' => self::GROUP_INVENTORY, 'label' => 'I/O rate',              'type' => 'float'],
            ['key' => 'inventory.io_rate_unit',       'group' => self::GROUP_INVENTORY, 'label' => 'I/O rate unit',         'type' => 'enum',   'enum' => ['week', 'month']],
            ['key' => 'inventory.pricing_model',      'group' => self::GROUP_INVENTORY, 'label' => 'Pricing model',         'type' => 'enum',   'enum' => ['cpm', 'io', 'both']],
            ['key' => 'inventory.weekly_impressions', 'group' => self::GROUP_INVENTORY, 'label' => 'Weekly impressions',    'type' => 'int'],
            ['key' => 'inventory.spot_length',        'group' => self::GROUP_INVENTORY, 'label' => 'Spot length (sec)',     'type' => 'int',    'unit' => 's'],
            ['key' => 'inventory.loop_length',        'group' => self::GROUP_INVENTORY, 'label' => 'Loop length (sec)',     'type' => 'int',    'unit' => 's'],
            ['key' => 'inventory.timezone',           'group' => self::GROUP_INVENTORY, 'label' => 'Timezone',              'type' => 'string'],
            ['key' => 'inventory.hours_open',         'group' => self::GROUP_INVENTORY, 'label' => 'Open hour (HH:MM)',     'type' => 'time',   'description' => 'Applied to all 7 days if hours_close also set'],
            ['key' => 'inventory.hours_close',        'group' => self::GROUP_INVENTORY, 'label' => 'Close hour (HH:MM)',    'type' => 'time'],
            ['key' => 'inventory.programmatic_enabled', 'group' => self::GROUP_INVENTORY, 'label' => 'Programmatic enabled', 'type' => 'bool'],
            ['key' => 'inventory.vn_category_name',   'group' => self::GROUP_INVENTORY, 'label' => 'Venue category (VN label)', 'type' => 'string', 'description' => 'Matched against venue_categories.name_vi'],
            ['key' => 'inventory.network_name',       'group' => self::GROUP_INVENTORY, 'label' => 'Network name',          'type' => 'string', 'description' => 'Auto-created if not found'],

            // ── Site / Location ────────────────────────────────────
            ['key' => 'site.external_id', 'group' => self::GROUP_SITE, 'label' => 'Site external ID', 'type' => 'string', 'description' => 'Lookup existing site by this code. If missing + site.name set → auto-create.'],
            ['key' => 'site.name',        'group' => self::GROUP_SITE, 'label' => 'Site name',        'type' => 'string'],
            ['key' => 'site.address',     'group' => self::GROUP_SITE, 'label' => 'Address',          'type' => 'string'],
            ['key' => 'site.city',        'group' => self::GROUP_SITE, 'label' => 'City / Province',  'type' => 'string'],
            ['key' => 'site.lat',         'group' => self::GROUP_SITE, 'label' => 'Latitude',         'type' => 'float'],
            ['key' => 'site.lon',         'group' => self::GROUP_SITE, 'label' => 'Longitude',        'type' => 'float'],
        ];
    }

    public static function get(string $key): ?array
    {
        foreach (self::all() as $f) {
            if ($f['key'] === $key) return $f;
        }
        return null;
    }

    public static function isValid(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Grouped for Filament Select options: ['Group' => ['key' => 'label', ...]]
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $grouped = [];
        foreach (self::all() as $f) {
            $grouped[$f['group']][$f['key']] = $f['label'];
        }
        return $grouped;
    }

    /**
     * Plain options [key => "Group · Label"] — fallback when grouped select isn't available.
     *
     * @return array<string, string>
     */
    public static function flatOptions(): array
    {
        $flat = [];
        foreach (self::all() as $f) {
            $flat[$f['key']] = "{$f['group']} · {$f['label']}";
        }
        return $flat;
    }

    /**
     * Compact schema string for LLM prompt — 1 line per field.
     */
    public static function promptSchema(): string
    {
        $lines = [];
        foreach (self::all() as $f) {
            $extra = [];
            if (! empty($f['required'])) $extra[] = 'REQUIRED';
            if (! empty($f['enum']))     $extra[] = 'enum:' . implode('|', $f['enum']);
            if (! empty($f['unit']))     $extra[] = 'unit:' . $f['unit'];
            if (! empty($f['description'])) $extra[] = $f['description'];
            $extraStr = $extra ? ' — ' . implode(' · ', $extra) : '';

            $lines[] = "- {$f['key']} ({$f['type']}): {$f['label']}{$extraStr}";
        }
        return implode("\n", $lines);
    }

    public static function requiredKeys(): array
    {
        return array_column(array_filter(self::all(), fn ($f) => $f['required'] ?? false), 'key');
    }
}
