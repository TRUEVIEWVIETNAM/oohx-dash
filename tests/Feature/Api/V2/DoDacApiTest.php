<?php

namespace Tests\Feature\Api\V2;

use App\Services\DoDacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `POST /api/v2/do-dac` — lát 1 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * Ba ca plan đòi: danh sách trắng, phép cộng dồn, hạn mức. Mấy ca còn lại ở
 * đây canh những chỗ **sai im lặng** — loại lỗi mà một máy đếm mắc phải thì
 * không gì đỏ, chỉ có một con số thấp hơn hoặc cao hơn sự thật, và không ai
 * biết cho tới lúc mang nó đi quyết định.
 */
class DoDacApiTest extends TestCase
{
    use RefreshDatabase;

    private function gui(array $than): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v2/do-dac', $than);
    }

    /**
     * `/api/v2/*` trả `{error, message, code, details[]}`, không phải
     * `{message, errors}` của Laravel — xem `bootstrap/app.php`. Nên
     * `assertJsonValidationErrors()` **không** dùng được ở đây: nó tìm khoá
     * `errors` và không thấy, dù phản hồi đúng 422 và đúng trường.
     */
    private function assertLoiTruong(\Illuminate\Testing\TestResponse $phanHoi, string ...$truong): void
    {
        $phanHoi->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);

        $coLoi = collect($phanHoi->json('details'))->pluck('field')->all();

        foreach ($truong as $t) {
            $this->assertContains($t, $coLoi, "Phản hồi 422 không nói gì về trường `{$t}`.");
        }
    }

    private function hopLe(array $ghiDe = []): array
    {
        return array_merge([
            'su_kien'  => 'quyen_vi_tri',
            'gia_tri'  => 'tu_choi',
            'thiet_bi' => 'mobile',
        ], $ghiDe);
    }

    public function test_cap_hop_le_duoc_ghi_va_tra_204_than_rong(): void
    {
        $phanHoi = $this->gui($this->hopLe());

        $phanHoi->assertNoContent();
        $this->assertSame('', $phanHoi->getContent(), 'Phản hồi có thân — không có gì để dò ở đây.');

        $this->assertDatabaseHas('do_dac_ngay', [
            'su_kien'  => 'quyen_vi_tri',
            'gia_tri'  => 'tu_choi',
            'thiet_bi' => 'mobile',
            'so_luot'  => 1,
        ]);
    }

    /**
     * Ca này là lý do bảng có khoá duy nhất bốn cột.
     *
     * Hai lượt cùng ô phải ra `so_luot = 2` trong **một** hàng, không phải
     * hai hàng. Hai hàng thì bảng lớn theo số lượt truy cập, và nó thôi là
     * một máy đếm — nó thành một nhật ký sự kiện, thứ plan cố ý không làm.
     */
    public function test_hai_luot_cung_o_cong_don_vao_mot_hang(): void
    {
        $this->gui($this->hopLe())->assertNoContent();
        $this->gui($this->hopLe())->assertNoContent();

        $this->assertSame(
            1,
            DB::table('do_dac_ngay')->count(),
            'Hai lượt cùng ô sinh ra hai hàng — bảng sẽ lớn theo số lượt truy cập.',
        );

        $this->assertSame(
            2,
            (int) DB::table('do_dac_ngay')->value('so_luot'),
            'Lượt thứ hai không cộng vào — `upsert()` của Laravel ghi đè bằng 1 thay vì cộng, '
            . 'và mỗi ô sẽ vĩnh viễn bằng 1 mà trông hoàn toàn bình thường.',
        );
    }

    public function test_bon_cot_khoa_tach_o_khac_nhau(): void
    {
        $this->gui($this->hopLe(['gia_tri' => 'tu_choi',  'thiet_bi' => 'mobile']));
        $this->gui($this->hopLe(['gia_tri' => 'tu_choi',  'thiet_bi' => 'desktop']));
        $this->gui($this->hopLe(['gia_tri' => 'cho_phep', 'thiet_bi' => 'mobile']));

        $this->assertSame(3, DB::table('do_dac_ngay')->count());
        $this->assertSame(
            [1, 1, 1],
            DB::table('do_dac_ngay')->pluck('so_luot')->map(fn ($n) => (int) $n)->all(),
        );
    }

    /**
     * Danh sách trắng — lớp chặn, không phải phép kiểm lịch sự.
     *
     * Đây là đường ghi mở cho người lạ. Thiếu nó thì bất cứ ai cũng dựng được
     * vài triệu hàng rác, và bảng mất đúng tính chất đang giữ nó đọc được.
     */
    public function test_su_kien_la_bi_tu_choi_va_khong_ghi_gi(): void
    {
        $this->assertLoiTruong($this->gui($this->hopLe(['su_kien' => 'theo_doi_nguoi_dung'])), 'su_kien');

        $this->assertSame(0, DB::table('do_dac_ngay')->count());
    }

    public function test_gia_tri_la_bi_tu_choi_va_khong_ghi_gi(): void
    {
        $this->assertLoiTruong($this->gui($this->hopLe(['gia_tri' => 'dia_chi_email'])), 'gia_tri');

        $this->assertSame(0, DB::table('do_dac_ngay')->count());
    }

    public function test_thiet_bi_la_bi_tu_choi(): void
    {
        $this->assertLoiTruong($this->gui($this->hopLe(['thiet_bi' => 'Mozilla/5.0 (iPhone)'])), 'thiet_bi');

        $this->assertSame(0, DB::table('do_dac_ngay')->count());
    }

    /**
     * Hoán vị hai trường: cả hai chuỗi đều hợp lệ ở đâu đó, cặp thì không.
     *
     * Ca này đi qua nhánh `su_kien` — `'tu_choi'` không phải một sự kiện.
     * Nó **không** canh tính chất "theo cặp"; ca dưới mới canh.
     */
    public function test_hoan_vi_su_kien_va_gia_tri_bi_tu_choi(): void
    {
        $this->assertLoiTruong(
            $this->gui(['su_kien' => 'tu_choi', 'gia_tri' => 'quyen_vi_tri', 'thiet_bi' => 'mobile']),
            'su_kien',
        );

        $this->assertSame(0, DB::table('do_dac_ngay')->count());
    }

    /**
     * Danh sách trắng tra theo CẶP, không gộp mọi giá trị lại.
     *
     * Ca này tồn tại vì một đột biến ngày 23/10/2026: nới phép kiểm từ
     * `CHO_PHEP[$suKien]` sang `array_merge(...array_values(CHO_PHEP))` —
     * tức chấp nhận bất kỳ giá trị nào của bất kỳ sự kiện nào — và **không
     * ca nào đỏ**. Mọi ca khác vẫn xanh, 12/12.
     *
     * Lý do lỗ không lộ: danh sách thật hôm nay có đúng **một** sự kiện, nên
     * không gửi được bộ ba *(sự kiện hợp lệ, giá trị thuộc sự kiện khác)* —
     * đúng cái duy nhất phân biệt hai cách viết. Lỗ sẽ mở ra thật khi lát 4
     * thêm sự kiện thứ hai, tức đúng lúc không ai còn nhớ chỗ này.
     *
     * Nên ca gọi thẳng service với một bảng **giả** hai sự kiện. Đó là lý do
     * `loiDauTien()` nhận tham số `$choPhep`, và docblock của nó nói thế.
     */
    public function test_gia_tri_dung_nhung_thuoc_su_kien_khac_bi_tu_choi(): void
    {
        $gia = [
            'su_kien_a' => ['x'],
            'su_kien_b' => ['y'],
        ];

        $doDac = app(DoDacService::class);

        $this->assertNull(
            $doDac->loiDauTien('su_kien_a', 'x', 'mobile', $gia),
            'Cặp đúng lại bị từ chối.',
        );

        $loi = $doDac->loiDauTien('su_kien_a', 'y', 'mobile', $gia);

        $this->assertNotNull(
            $loi,
            'Giá trị `y` thuộc `su_kien_b` lại được nhận dưới `su_kien_a` — danh sách trắng '
            . 'đang gộp mọi giá trị thay vì tra theo cặp.',
        );
        $this->assertSame('gia_tri', $loi[0]);
    }

    /**
     * Luật danh sách trắng sống ở service, không ở FormRequest.
     *
     * Bản đầu của `GhiDoDacRequest` tự tra `CHO_PHEP` và tự dựng lại cùng ba
     * phép kiểm — hai bản luật cho một thứ, và `DoDacService::hopLe()` thành
     * mã chết không ai gọi. Ca trên chỉ canh được service; ca này canh rằng
     * đường HTTP thật sự đi qua đúng service ấy.
     */
    public function test_form_request_goi_service_chu_khong_tu_viet_luat(): void
    {
        $ma = php_strip_whitespace(app_path('Http/Requests/GhiDoDacRequest.php'));

        $this->assertStringContainsString(
            'loiDauTien(',
            $ma,
            'FormRequest không gọi `DoDacService::loiDauTien()` nữa.',
        );

        $this->assertStringNotContainsString(
            'CHO_PHEP[',
            $ma,
            'FormRequest lại tự tra danh sách trắng — đó là bản luật thứ hai, và hai bản '
            . 'sẽ lệch.',
        );
    }

    public function test_thieu_truong_thi_422(): void
    {
        $this->assertLoiTruong(
            $this->postJson('/api/v2/do-dac', ['su_kien' => 'quyen_vi_tri']),
            'gia_tri',
            'thiet_bi',
        );
    }

    /**
     * Không nhận gì ngoài ba trường — kể cả khi phía gọi gửi thêm.
     *
     * Ca này canh một thứ không nhìn thấy trong mã: **bảng không có chỗ** cho
     * dữ liệu nhận dạng được. Nếu một ngày ai đó thêm cột `ip` và cho request
     * ghi vào, ca này đỏ — và đó đúng là lúc cần một quyết định chứ không
     * phải một commit.
     */
    public function test_khong_luu_gi_nhan_dang_duoc_du_client_gui_them(): void
    {
        $this->gui($this->hopLe([
            'ip'         => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0',
            'user_id'    => 'ai-do',
            'session'    => 'abc123',
        ]))->assertNoContent();

        $hang = (array) DB::table('do_dac_ngay')->first();

        $this->assertSame(
            ['ngay', 'su_kien', 'gia_tri', 'thiet_bi', 'so_luot'],
            array_keys($hang),
            'Bảng đếm có thêm cột ngoài năm cột gộp. Thêm một cột định danh được ở đây '
            . 'là biến bảng này thành bảng hành vi — một plan khác, cần đồng ý của người '
            . 'dùng và một mục trong chính sách bảo mật.',
        );
    }

    /**
     * Cột `ngay` theo giờ Việt Nam, không theo UTC.
     *
     * `config('app.timezone')` là `UTC`. Nếu ghi bằng `now()->toDateString()`
     * thì **bảy giờ đầu mỗi ngày ở Việt Nam rơi vào ngày hôm trước** — widget
     * "hôm nay" thiếu hẳn buổi sáng, đều đặn, và không gì đỏ.
     *
     * Ca đặt đồng hồ vào 00:30 giờ Việt Nam ngày 15/3, tức 17:30 UTC ngày
     * 14/3, rồi đòi hàng mang ngày **15**.
     */
    public function test_ngay_tinh_theo_gio_viet_nam_khong_phai_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-14 17:30:00', 'UTC'));

        $this->gui($this->hopLe())->assertNoContent();

        $this->assertSame(
            '2026-03-15',
            substr((string) DB::table('do_dac_ngay')->value('ngay'), 0, 10),
            'Ngày ghi theo UTC, nên bảy giờ đầu mỗi ngày ở Việt Nam bị dồn vào hôm trước.',
        );

        Carbon::setTestNow();
    }

    /**
     * Hạn mức: `30,60`.
     *
     * Không đếm đúng 31 lượt rồi đòi lượt thứ 31 đỏ — con số đó là một lựa
     * chọn có thể đổi, và một ca khoá cứng vào nó sẽ đỏ mỗi lần ai đó chỉnh
     * mức, kể cả khi chỉnh đúng. Ca chỉ đòi: **có** một trần, và nó chặn.
     */
    public function test_co_tran_han_muc_tren_duong_ghi_mo(): void
    {
        $tran = 30;

        for ($i = 0; $i < $tran; $i++) {
            $this->gui($this->hopLe())->assertNoContent();
        }

        $this->gui($this->hopLe())->assertStatus(429);
    }

    /**
     * Không chỗ nào ngoài service biết các giá trị hợp lệ.
     *
     * Ca `test_form_request_goi_service_chu_khong_tu_viet_luat` ở trên canh
     * *luật*; ca này canh *dữ liệu*. Hai thứ chép được độc lập: ai đó có thể
     * vẫn gọi `loiDauTien()` mà đồng thời viết thêm một `in_array($giaTri,
     * ['hoi', 'cho_phep', …])` cho "nhanh", và bản thứ hai ấy sẽ lệch — đúng
     * loại lỗi mà `InventoryController` với `FrontpageService` đã mắc một lần
     * (CLAUDE.md mục 1).
     */
    public function test_khong_chep_gia_tri_ra_ngoai_service(): void
    {
        $ma = php_strip_whitespace(app_path('Http/Requests/GhiDoDacRequest.php'));

        foreach (DoDacService::CHO_PHEP[DoDacService::SU_KIEN_QUYEN_VI_TRI] as $giaTri) {
            $this->assertStringNotContainsString(
                "'" . $giaTri . "'",
                $ma,
                'FormRequest chép giá trị `' . $giaTri . '` thành bản thứ hai của danh sách '
                . 'trắng. Hai bản sẽ lệch.',
            );
        }
    }
}
