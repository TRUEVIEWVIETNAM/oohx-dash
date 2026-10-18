<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `map:kiem-tile` phải bắt được đúng kiểu hỏng đã lọt lên production.
 *
 * Trưa 18/10/2026, `basemaps.cartocdn.com` trả **HTTP 200**, Content-Type
 * `image/png`, 2.049 byte — một tấm watermark "API KEY REQUIRED". Mọi lớp
 * canh lúc đó đều xanh: mã trạng thái đúng, kiểu nội dung đúng, Leaflet không
 * bắn `tileerror`, không ngoại lệ nào ném ra. Mười hai bản đồ lên production
 * với chữ đó trên nền.
 *
 * Ca `test_hai_nghin_byte_watermark_la_hong` dựng lại đúng phản hồi ấy. Nó là
 * lý do tệp này tồn tại; hai ca còn lại chỉ canh hai đầu còn lại của lệnh.
 */
class KiemNguonTileTest extends TestCase
{
    /** Byte giả, đủ dài để qua ngưỡng — nội dung không quan trọng, độ dài thì có. */
    private function anh(int $byte): string
    {
        return str_repeat('x', $byte);
    }

    public function test_tile_that_thi_xanh(): void
    {
        Http::fake(['*' => Http::response($this->anh(27_862), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('map:kiem-tile')->assertSuccessful();
    }

    public function test_hai_nghin_byte_watermark_la_hong(): void
    {
        // Đúng phản hồi của Carto ngày 18/10/2026: 200, image/png, 2.049 byte.
        Http::fake(['*' => Http::response($this->anh(2_049), 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('map:kiem-tile')
            ->expectsOutputToContain('watermark')
            ->assertFailed();
    }

    public function test_503_la_hong(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('map:kiem-tile')
            ->expectsOutputToContain('HTTP 503')
            ->assertFailed();
    }

    public function test_200_nhung_tra_html_la_hong(): void
    {
        // Trang đăng nhập hoặc trang lỗi của CDN trả về thay cho ảnh.
        Http::fake(['*' => Http::response('<html>…</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('map:kiem-tile')
            ->expectsOutputToContain('không phải ảnh')
            ->assertFailed();
    }

    public function test_url_mau_thay_du_bon_cho(): void
    {
        Http::fake(['*' => Http::response($this->anh(27_862), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('map:kiem-tile')->assertSuccessful();

        Http::assertSent(function (Request $r) {
            // Còn sót một chỗ thay nghĩa là lệnh xin một URL không tồn tại và
            // báo "HỎNG" cho một nguồn đang sống — tệ hơn là không kiểm.
            foreach (['{z}', '{x}', '{y}', '{s}', '{r}'] as $cho) {
                $this->assertStringNotContainsString($cho, $r->url());
            }

            return true;
        });
    }
}
