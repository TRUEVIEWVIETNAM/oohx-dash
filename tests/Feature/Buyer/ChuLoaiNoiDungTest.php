<?php

namespace Tests\Feature\Buyer;

use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hai trang đặt chỗ hiện **chữ** loại nội dung, không hiện mã.
 *
 * ══ Lỗi tệp này chống ══
 *
 * `buyer/booking/creative` và `buyer/booking/review` in `strtoupper($c->type)`,
 * nên `vast_tag` hiện ra **`VAST_TAG`** — một mã CSDL kèm dấu gạch dưới, trên
 * trang người mua.
 *
 * ══ Vì sao cần tệp này khi đã có `NhanEnumMotNoiTest` ══
 *
 * Tệp kia canh bảng chữ **đầy đủ và sạch**. Nó không canh việc trang có dùng
 * bảng đó hay không: trả hai dòng này về `strtoupper($c->type)` thì mọi phép
 * kiểm ở đó vẫn xanh. Hai việc khác nhau, nên hai chỗ canh.
 *
 * ══ Vì sao đọc đúng ô chữ, không `assertSee` cả trang ══
 *
 * Mã loại còn xuất hiện ở chỗ khác trong HTML — thuộc tính, tên tệp — nên một
 * phép kiểm toàn trang sẽ đỏ vì lý do sai. Hai trang đặt chữ loại vào cùng một
 * ô: `div` có `font-size:11px`, ngay dưới tên nội dung.
 */
class ChuLoaiNoiDungTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create(['status' => 'active']);

        $this->buyer = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD loại nội dung',
            'start_date'      => now()->addMonth(),
            'end_date'        => now()->addMonths(2),
            'status'          => Campaign::STATUS_DRAFT,
        ]);

        // Một nội dung cho MỖI loại: lỗi cũ chỉ hiện ở `vast_tag`, nhưng một ca
        // chỉ thử `vast_tag` sẽ không thấy nếu ai đó sửa bằng cách đặc cách
        // đúng một mã.
        foreach (array_keys(Creative::TYPE_LABELS) as $loai) {
            Creative::create([
                'campaign_id'     => $this->campaign->id,
                'organization_id' => $org->id,
                'name'            => 'Nội dung ' . $loai,
                'type'            => $loai,
                'file_size'       => 102_400,
                'status'          => Creative::STATUS_PENDING_REVIEW,
            ]);
        }
    }

    /** @return array<int, string> chữ trong ô loại, theo thứ tự xuất hiện */
    private function chuTrenO(string $html): array
    {
        preg_match_all(
            '/<div style="font-size:11px;color:var\(--t4\)">(.*?)<\/div>/s',
            $html,
            $khop,
        );

        return array_map(fn (string $s) => trim(strip_tags($s)), $khop[1]);
    }

    private function khangDinh(string $html, string $trang): void
    {
        $o = $this->chuTrenO($html);

        $this->assertCount(
            count(Creative::TYPE_LABELS),
            $o,
            "{$trang}: phải có đúng một ô loại cho mỗi nội dung",
        );

        foreach (Creative::TYPE_LABELS as $ma => $chu) {
            $this->assertTrue(
                (bool) array_filter($o, fn (string $s) => str_starts_with($s, $chu)),
                "{$trang}: không thấy chữ \"{$chu}\" của loại `{$ma}`. Đang có: "
                . implode(' | ', $o),
            );
        }

        // `VAST_TAG` là hình dạng cụ thể của lỗi cũ (`strtoupper('vast_tag')`),
        // nên nói thẳng tên nó ra: một lần hồi quy sẽ đọc được ngay.
        foreach ($o as $s) {
            $this->assertStringNotContainsString(
                '_',
                $s,
                "{$trang}: ô loại còn in mã CSDL (\"{$s}\") — `strtoupper` đã quay lại?",
            );
        }
    }

    /**
     * Hai ca cũ đọc ô loại trong HTML của hai trang đặt chỗ. Từ 10/10/2026 hai
     * trang đó đọc `/api/v2` nên máy chủ không render ô nào — nhưng thứ hai ca
     * đó canh **không mất**, nó chuyển chỗ:
     *
     *  - chữ do API trả: `test_api_tra_chu_cho_moi_loai` ngay dưới, đọc
     *    `GET /api/v2/campaigns/{campaign}` — tức đúng đường trang gọi;
     *  - chữ tới được DOM: `tests/js/noi-dung.test.mjs` và `xem-lai.test.mjs`,
     *    chạy trên đúng khối script sẽ lên production.
     *
     * Phép kiểm "không có dấu gạch dưới" — hình dạng cụ thể của lỗi
     * `strtoupper('vast_tag')` → `VAST_TAG` — giữ nguyên, chỉ đổi chỗ đọc.
     */
    public function test_api_tra_chu_cho_moi_loai(): void
    {
        $ds = $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $this->campaign->id)
            ->assertOk()
            ->json('data.creatives');

        $this->assertCount(
            count(Creative::TYPE_LABELS),
            $ds,
            'API phải trả đúng một nội dung cho mỗi loại',
        );

        $chu = array_map(fn (array $c) => $c['type_label'], $ds);

        foreach (Creative::TYPE_LABELS as $ma => $nhan) {
            $this->assertContains($nhan, $chu, "Không thấy chữ \"{$nhan}\" của loại `{$ma}`");
        }

        foreach ($chu as $s) {
            $this->assertStringNotContainsString(
                '_',
                $s,
                "`type_label` còn in mã CSDL (\"{$s}\") — `strtoupper` đã quay lại?",
            );
        }
    }

    /** Hai trang KHÔNG render sẵn chữ loại trong HTML nữa. */
    public function test_hai_trang_khong_render_san_chu_loai(): void
    {
        foreach (['creative', 'review'] as $buoc) {
            $html = $this->actingAs($this->buyer)
                ->get('/booking/' . $this->campaign->id . '/' . $buoc)
                ->assertOk()
                ->getContent();

            $this->assertSame(
                [],
                $this->chuTrenO($html),
                "trang {$buoc}: vẫn còn ô loại render ở máy chủ — chữ phải tới qua API",
            );
        }
    }
}
