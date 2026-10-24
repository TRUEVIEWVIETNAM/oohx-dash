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
 * `photos` của `/api/v1/inventory/screens/{id}` — ghim hình dạng.
 *
 * ══ Vì sao tệp này tồn tại ══
 *
 * Lát 1 của `docs/PLAN-ANH-VI-TRI.md` gom ba bản của phép "đường dẫn trong cột
 * → URL" về một bản ở `ScreenSpec::photoUrlOf()`. Hai trong ba bản là accessor
 * mà **`/api/v1` đang đọc** (`ScreenResource:53` → `$spec->photo_urls`), và
 * `CLAUDE.md` mục 2 nói: *không đổi hành vi `/api/v1`* — đó là hợp đồng với
 * đối tác. Nên hình dạng phải được ghim, không chỉ được tin.
 *
 * ══ Một điều phát hiện khi viết tệp này ══
 *
 * `/api/v1` chỉ nạp **cột `photo_url`** của spec, trừ khi có `?include=adops`
 * (`InventoryController::resolveRelations()`). Nghĩa là mảng `photos` của
 * phản hồi mặc định **không bao giờ** chứa nhiều hơn một ảnh, dù màn hình có
 * mười ảnh trong `photos[]` — và điều đó đúng từ trước lát này, không phải do
 * nó. Ghim cả hai nhánh ở đây để lần sau ai đọc cũng thấy ngay, thay vì tìm
 * trong một danh sách cột eager-load.
 *
 * Phiên bản đầu của tệp này đo nhánh mặc định rồi khẳng định như thể nó là
 * nhánh đầy đủ, và **CI bắt được** — ba ca đỏ trong khi 1.270 ca còn lại xanh.
 *
 * ══ Một điểm KHÁC trước, nói rõ ══
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
 * `ScreenSpec::photoPaths()`, và ca cuối tệp này đỏ chỉ ngay vào nó.
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
    private function manHinh(array $spec): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active', 'city' => 'Hà Nội']);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(array_merge(['screen_id' => $screen->id], $spec));

        return $screen;
    }

    /**
     * Mảng `photos` của phản hồi v1 cho một màn hình.
     *
     * @param  array<string, mixed>  $spec
     * @param  bool  $adops  `?include=adops` — nhánh duy nhất nạp cả cột `photos`
     * @return list<string>
     */
    private function anhCua(array $spec, bool $adops = true): array
    {
        $screen = $this->manHinh($spec);

        $duong = '/api/v1/inventory/screens/' . $screen->external_id
            . ($adops ? '?include=adops' : '');

        $data = $this->withToken($this->token)->getJson($duong)->assertOk()->json('data');

        $this->assertIsArray($data, 'Phản hồi v1 không có khối `data`.');
        $this->assertArrayHasKey('photos', $data, 'Trường `photos` biến mất khỏi /api/v1.');

        return $data['photos'];
    }

    // ── Hai dạng dữ liệu thật, phải ra y như trước ──────────────────────────

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
        $this->assertSame(
            ['https://doitac.example/a.jpg'],
            $this->anhCua(['photos' => ['https://doitac.example/a.jpg']]),
        );
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
     * Phản hồi **mặc định** chỉ đọc cột `photo_url`.
     *
     * `resolveRelations()` nạp `spec:screen_id,photo_url,…` khi không có
     * `?include=adops`, nên cột `photos` không được chọn và accessor rơi về
     * nhánh dự phòng. Đây là hành vi có từ trước lát 1; ghim nó để lần sau
     * không ai mất một buổi đi tìm vì sao đối tác chỉ thấy một ảnh.
     */
    public function test_nhanh_mac_dinh_chi_tra_mot_anh_tu_photo_url(): void
    {
        $anh = $this->anhCua([
            'photos'    => ['screen-photos/a.jpg', 'screen-photos/b.jpg'],
            'photo_url' => 'screen-photos/cu.jpg',
        ], adops: false);

        $this->assertCount(1, $anh);
        $this->assertStringContainsString('screen-photos/cu.jpg', $anh[0]);
    }

    /**
     * Điểm duy nhất khác trước — xem docblock đầu tệp.
     *
     * Ca này **ghim thay đổi**, không chỉ chấp nhận nó.
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
