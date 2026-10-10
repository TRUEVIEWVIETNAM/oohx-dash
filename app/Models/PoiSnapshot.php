<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * POI snapshot per (lat, lon, radius, source).
 *
 * Persists POI data outside of volatile Laravel cache.
 * Designed to support future POI microservice that writes scoring/features.
 *
 * Lookup pattern:
 *   $snap = PoiSnapshot::freshFor($lat, $lon, 500, 'osm')->first();
 *   if ($snap) $pois = $snap->pois;
 */
class PoiSnapshot extends Model
{
    public const SOURCE_OSM           = 'osm';
    public const SOURCE_GOOGLE_PLACES = 'google_places';
    public const SOURCE_FOURSQUARE    = 'foursquare';

    /**
     * Chữ hiển thị cho nguồn dữ liệu POI — và chúng KHÔNG phải tiếng Việt.
     *
     * Đây là tên riêng của ba dịch vụ. "OpenStreetMap" dịch sang tiếng Việt thì
     * sai, không phải chưa dịch. Thứ cần sửa ở đây là chuyện khác: cột in `osm`
     * và `google_places` nguyên văn — mã CSDL có dấu gạch dưới, không phải tên
     * mà chủ sở hữu viết.
     *
     * Cột là `varchar(32)`, không phải enum, nên `NhanEnumMotNoiTest` đối chiếu
     * bảng này với ba hằng `SOURCE_*` ngay trên; `poi_snapshots` được khai là
     * ngoại lệ ở đó kèm lý do.
     */
    public const SOURCE_LABELS = [
        self::SOURCE_OSM           => 'OpenStreetMap',
        self::SOURCE_GOOGLE_PLACES => 'Google Places',
        self::SOURCE_FOURSQUARE    => 'Foursquare',
    ];

    protected $fillable = [
        'lat_key', 'lon_key', 'radius', 'source',
        'pois', 'poi_count', 'features', 'scoring',
        'fetched_at', 'expires_at',
    ];

    protected $casts = [
        'pois'       => 'array',
        'features'   => 'array',
        'scoring'    => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Round lat/lon to 5 decimals (~1m precision) for cache-key match.
     * Same format as PoiContextEnricher uses.
     */
    public static function locationKey(float $lat, float $lon): array
    {
        return [
            number_format($lat, 5, '.', ''),
            number_format($lon, 5, '.', ''),
        ];
    }

    /**
     * Find non-expired snapshot for given location + source.
     */
    public function scopeFreshFor(Builder $q, float $lat, float $lon, int $radius = 500, string $source = 'osm'): Builder
    {
        [$latKey, $lonKey] = self::locationKey($lat, $lon);

        return $q->where('lat_key', $latKey)
            ->where('lon_key', $lonKey)
            ->where('radius', $radius)
            ->where('source', $source)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Upsert snapshot for given location.
     */
    public static function upsertSnapshot(
        float $lat,
        float $lon,
        int $radius,
        string $source,
        array $pois,
        ?array $features = null,
        ?int $ttlMinutes = null,
    ): self {
        [$latKey, $lonKey] = self::locationKey($lat, $lon);

        return self::updateOrCreate(
            [
                'lat_key' => $latKey,
                'lon_key' => $lonKey,
                'radius'  => $radius,
                'source'  => $source,
            ],
            [
                'pois'       => $pois,
                'poi_count'  => count($pois),
                'features'   => $features,
                'fetched_at' => now(),
                'expires_at' => $ttlMinutes ? now()->addMinutes($ttlMinutes) : null,
            ],
        );
    }
}
