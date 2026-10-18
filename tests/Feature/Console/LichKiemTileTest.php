<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * `map:kiem-tile` phải nằm trong lịch, và phải chạy đủ thường.
 *
 * ══ Vì sao một mục lịch đáng được canh ══
 *
 * Ngày 18/10/2026 nguồn ảnh nền bản đồ hỏng bốn lần và không lần nào có một
 * dòng lỗi nào được ghi ở đâu: OSM 503, Carto trả 200 kèm ảnh "API KEY
 * REQUIRED", khóa MapTiler không tới máy chủ vì deploy chạy bản cũ của chính
 * nó, rồi style rỗng cho URL `maps//{z}/…`. Lần nào bản đồ cũng **trông như
 * đang chạy**.
 *
 * Lệnh này là lớp canh duy nhất bắt được cả bốn, nên nó biến mất khỏi lịch
 * trong một lần dọn dẹp là quay lại đúng tình trạng đó — và lần sau sẽ không
 * ai nhớ vì sao từng có nó.
 */
class LichKiemTileTest extends TestCase
{
    private function mucLich(string $lenh): ?\Illuminate\Console\Scheduling\Event
    {
        foreach (app(Schedule::class)->events() as $su_kien) {
            if (str_contains((string) $su_kien->command, $lenh)) {
                return $su_kien;
            }
        }

        return null;
    }

    public function test_map_kiem_tile_nam_trong_lich(): void
    {
        $this->assertNotNull(
            $this->mucLich('map:kiem-tile'),
            '`map:kiem-tile` không còn trong lịch — lớp duy nhất phát hiện '
            . 'nguồn tile trả rác đã biến mất.',
        );
    }

    public function test_chay_it_nhat_moi_ngay_mot_lan(): void
    {
        $su_kien = $this->mucLich('map:kiem-tile');

        // Biểu thức cron phải có một trường giờ cụ thể hoặc bước giờ — tức
        // không phải `@monthly`. Một phép soát mỗi tháng để một sự cố sống
        // ba tuần thì không khác gì không soát.
        $this->assertMatchesRegularExpression(
            '/^\S+ (\*|\*\/\d+|[\d,\-\/]+) /',
            $su_kien->expression,
            "Lịch `{$su_kien->expression}` thưa hơn một ngày một lần.",
        );
    }

    public function test_khong_chong_lan_va_ghi_ra_log_rieng(): void
    {
        $su_kien = $this->mucLich('map:kiem-tile');

        // Mạng chậm làm một lần chạy kéo dài; không chặn chồng lấn thì các
        // lần sau xếp hàng và mỗi lần lại tiêu thêm tile của hạn mức.
        $this->assertNotNull(
            $su_kien->mutexName(),
            'Thiếu `withoutOverlapping()`.',
        );

        $this->assertStringContainsString(
            'oohx-map-tile.log',
            (string) $su_kien->output,
            'Không ghi ra log riêng, nên khi có chuyện thì không có gì để tra.',
        );
    }
}
