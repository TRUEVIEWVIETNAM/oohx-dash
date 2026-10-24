<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screen technical specifications.
 *
 * Marketplace fields (Phase 1):
 * @property int         $width_px
 * @property int         $height_px
 * @property float|null  $width_cm
 * @property float|null  $height_cm
 * @property string|null $photo_url
 * @property bool        $allow_image
 * @property bool        $allow_video
 *
 * @internal AdOps fields (Phase 2 — hidden from frontpage):
 * @property string|null $resolution_preset
 * @property string|null $width_unit
 * @property string|null $height_unit
 * @property string|null $facing_direction
 * @property bool        $allow_html
 * @property bool        $allow_zip
 * @property bool        $allow_vast
 * @property array|null  $allowed_languages
 */
class ScreenSpec extends Model
{
    use HasFactory;

    protected $table = 'screen_specs';

    protected $fillable = [
        // Marketplace
        'screen_id', 'width_px', 'height_px', 'width_cm', 'height_cm',
        'photo_url', 'photos', 'allow_image', 'allow_video',

        // AdOps (Phase 2)
        'resolution_preset', 'width_unit', 'height_unit',
        'facing_direction',
        'allow_html', 'allow_zip', 'allow_vast',
        'allowed_languages',
    ];

    protected $casts = [
        'allow_image'       => 'boolean',
        'allow_video'       => 'boolean',
        'allow_html'        => 'boolean',
        'allow_zip'         => 'boolean',
        'allow_vast'        => 'boolean',
        'allowed_languages' => 'array',
        'photos'            => 'array',
        'width_cm'          => 'decimal:1',
        'height_cm'         => 'decimal:1',
    ];

    // ── Relationships ───────────────────────────────────────

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    // ── Accessors ───────────────────────────────────────────

    /**
     * Đường dẫn ảnh **đúng như trong cột**, đã lọc.
     *
     * ══ Vì sao phép lọc nằm ở đây, một chỗ ══
     *
     * Trước 24/10/2026 có **ba** bản của cùng phép này: hai accessor dưới đây
     * và một vòng `map` trong `ScreenDetailResource`. Bản thứ ba thiếu phép
     * kiểm `http(s)://`, nên một hàng `photos: ["https://doitac.example/a.jpg"]`
     * hiện bình thường ở thẻ danh sách (đi qua accessor) và ra
     * `https://oohx.net/storage/https://doitac.example/a.jpg` ở trang chi tiết.
     * Hai đường đọc cho cùng một màn hình trả dữ liệu khác nhau — hình dạng
     * lỗi R38, và `CLAUDE.md` mục 1 dặn đừng lặp lại.
     *
     * Chỗ sửa không phải bản thứ ba. Chỗ sửa là **xoá bản thứ ba đi**.
     *
     * ══ Lọc gì, và vì sao ══
     *
     * Phần tử không phải chuỗi, hoặc rỗng sau khi bỏ khoảng trắng, không phải
     * một ảnh. Để nó đi tiếp thì nó ra `asset('storage/')` — tức
     * `https://oohx.net/storage/`, một URL không trỏ vào ảnh nào. Đếm nó là
     * một ảnh làm hồ sơ trông đầy hơn thực tế, và hiện nó làm trang có một ô
     * ảnh vỡ.
     *
     * @return list<string>
     */
    public function photoPaths(): array
    {
        $tho = is_array($this->photos) ? $this->photos : [];

        // `photo_url` là nhánh dự phòng, không phải nhánh cộng dồn: mọi hàng
        // nhập trước migration `add_photos_to_screen_specs_table` chỉ có nó, và
        // migration đó đã chép sang `photos` cho hàng nào có. Một hàng có cả
        // hai thì `photos` là bản đầy đủ.
        if ($tho === []) {
            $tho = $this->photo_url !== null ? [$this->photo_url] : [];
        }

        return array_values(array_filter(
            array_map(fn ($p) => is_string($p) ? trim($p) : '', $tho),
            fn (string $p) => $p !== '',
        ));
    }

    /**
     * Một đường dẫn trong cột → một URL dùng được.
     *
     * Cột nhận hai dạng, và cả hai đều có thật trong dữ liệu: đường dẫn tương
     * đối trong disk công khai (biểu mẫu tải ảnh của Filament ghi vào), và URL
     * đầy đủ (cột `spec.photo_url` của đường nhập kho nhận được). Bọc dạng thứ
     * hai bằng `asset('storage/')` cho ra một URL vô nghĩa.
     */
    public static function photoUrlOf(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . $path);
    }

    /** Ảnh đầu tiên dưới dạng URL đầy đủ, hoặc `null` khi không có ảnh nào. */
    public function getPhotoAttribute(): ?string
    {
        $duong = $this->photoPaths();

        return $duong === [] ? null : self::photoUrlOf($duong[0]);
    }

    /**
     * Toàn bộ ảnh dưới dạng URL đầy đủ.
     *
     * @return list<string>
     */
    public function getPhotoUrlsAttribute(): array
    {
        return array_map(fn (string $p) => self::photoUrlOf($p), $this->photoPaths());
    }

    public function getOrientationAttribute(): string
    {
        if ($this->width_px > $this->height_px) {
            return 'landscape';
        }
        if ($this->width_px < $this->height_px) {
            return 'portrait';
        }

        return 'square';
    }

    public function getAspectRatioAttribute(): string
    {
        if (! $this->width_px || ! $this->height_px) {
            return 'unknown';
        }

        $gcd = $this->gcd($this->width_px, $this->height_px);

        return ($this->width_px / $gcd) . ':' . ($this->height_px / $gcd);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
