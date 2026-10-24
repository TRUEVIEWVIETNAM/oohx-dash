<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `photos` của `/api/v1/inventory/screens` — ghim hình dạng.
 *
 * ══ Vì sao tệp này tồn tại ══
 *
 * Lát 1 của `docs/PLAN-ANH-VI-TRI.md` gom ba bản của phép "đường dẫn trong cột
 * → URL" về một bản ở `ScreenSpec::photoUrlOf()`. Hai trong ba bản là accessor
 * mà **`/api/v1` đang đọc** (`ScreenResource:53` → `$spec->photo_urls`), và
 * `CLAUDE.md` mục 2 nói: *không đổi hành vi `/api/v1`* — đó là hợp đồng với
 * đối tác.
 *
 * Nên hai dạng dữ liệu thật phải ra **y như trước**, và tệp này ghim chúng:
 * đường dẫn tương đối thành URL đầy đủ, URL đầy đủ giữ nguyên văn.
 *
 * ══ Một điểm KHÁC trước, nói rõ ở đây ══
 *
 * Phần tử rỗng hoặc không phải chuỗi nay bị **lọc** thay vì ra
 * `https://oohx.net/storage/`. Nó là một thay đổi của `/api/v1`, nên nó phải
 * được nhìn thấy chứ không nằm im:
 *
 *  - Trên dữ liệu production hôm 24/10/2026 thay đổi này **không chạm hàng
 *    nào**: cả 104 vị trí không có ảnh riêng, nên `photos` là `[]` ở mọi hàng.
 *  - Giá trị cũ không phải một URL ảnh. Một đối tác tải `storage/` về nhận
 *    một trang HTML hoặc 404, nên không có cách dùng nào của nó bị mất.
 *
 * Nếu chủ dự án muốn giữ nguyên cả phần tử rỗng thì đó là một dòng trong
 * `ScreenSpec::photoPaths()`, và ca cuối tệp này đổi theo.
 */
class AnhV1GiuHinhDangTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $client = ApiClient::create([
            'client_id'     => 'test_oohx',
            'client_secret' => bcrypt('secret'),
            'name'          => 'Test OOHX',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);

        $this->token = $client->createToken('test', ['inventory'])->plainTextToken;
    }

    /** @param array<string, mixed> $spec */
    private function anhCua(array $spec): array
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active', 'city' => 'Hà Nội']);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(array_merge(['screen_id' => $screen->id], $spec));

        $hang = collect(
            $this->withToken($this->token)
                ->getJson('/api/v1/inventory/screens?per_page=50')
                ->assertOk()
                ->json('data')
        )->firstWhere('screen_id', $screen->external_id)
            ?? collect(
                $this->withToken($this->token)
                    ->getJson('/api/v1/inventory/screens?per_page=50')
                    ->json('data')
            )->last();

        $this->assertIsArray($hang, 'Không thấy màn hình trong phản hồi v1.');
        $this->assertArrayHasKey('photos', $hang, 'Trường `photos` biến mất khỏi /api/v1.');

        return $hang['photos'];
    }

    public function test_duong_dan_tuong_doi_ra_url_day_du_nhu_truoc(): void
    {
        $anh = $this->anhCua(['photos' => ['screen-photos/a.jpg', 'screen-photos/b.webp']]);

        $this->assertCount(2, $anh);

        foreach ($anh as $url) {
            $this->assertStringStartsWith('http', $url);
            $this->assertStringContainsString('/storage/screen-photos/', $url);
        }
    }

    public function test_url_day_du_giu_nguyen_van_nhu_truoc(): void
    {
        $anh = $this->anhCua(['photos' => ['https://doitac.example/a.jpg']]);

        $this->assertSame(['https://doitac.example/a.jpg'], $anh);
    }

    public function test_photo_url_mot_minh_van_ra_mot_phan_tu_nhu_truoc(): void
    {
        $anh = $this->anhCua(['photos' => null, 'photo_url' => 'screen-photos/cu.jpg']);

        $this->assertCount(1, $anh);
        $this->assertStringContainsString('/storage/screen-photos/cu.jpg', $anh[0]);
    }

    public function test_khong_co_anh_thi_mang_rong_nhu_truoc(): void
    {
        $this->assertSame([], $this->anhCua(['photos' => null, 'photo_url' => null]));
    }

    /**
     * Điểm duy nhất khác trước — xem docblock đầu tệp.
     *
     * Ca này **ghim thay đổi**, không chỉ chấp nhận nó: nếu chủ dự án đảo
     * quyết định thì ca này đỏ và chỉ ngay vào dòng cần sửa.
     */
    public function test_phan_tu_rong_bi_loc_thay_vi_ra_url_tro_vao_khong_dau(): void
    {
        $anh = $this->anhCua(['photos' => ['', '   ', 'screen-photos/that.jpg']]);

        $this->assertCount(1, $anh, 'Phần tử rỗng phải bị lọc khỏi /api/v1.');
        $this->assertStringContainsString('screen-photos/that.jpg', $anh[0]);

        foreach ($anh as $url) {
            $this->assertStringNotContainsString(
                '/storage/"',
                $url . '"',
                'Một URL kết thúc ở /storage/ không trỏ vào ảnh nào.',
            );
        }
    }
}
