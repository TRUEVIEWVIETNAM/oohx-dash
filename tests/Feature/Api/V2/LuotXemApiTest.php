<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Services\LuotXemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `POST /api/v2/screens/{slug}/luot-xem` — lát 1 của `docs/PLAN-LUOT-XEM.md`.
 *
 * ══ Mọi lỗi của một máy đếm đều im lặng ══
 *
 * Đó là tiền đề của cả tệp này. Không có ngoại lệ nào được ném, không có trang
 * nào trắng, không có dòng nào trong log. Hậu quả duy nhất là một con số sai
 * trên một trang mà **không ai biết con số đúng là bao nhiêu** — nên nó sẽ
 * không bị phát hiện bằng cách dùng thử, chỉ bằng cách mang nó đi quyết định.
 *
 * Bốn lỗi dưới đây đều có hình dạng đó, và mỗi lỗi có một ca riêng ở đây:
 *
 *  1. **Ghi đè thay vì cộng dồn.** `upsert()` của Laravel sinh
 *     `so_luot = VALUES(so_luot)`. Mọi ô vĩnh viễn bằng 1, mọi vị trí "được
 *     xem đúng một lượt", và phép xếp hạng của lát 3 trả về một thứ tự tuỳ ý.
 *  2. **Cột `ngay` theo UTC.** Bảy giờ đầu mỗi ngày ở Việt Nam dồn vào ngày
 *     hôm trước — tức ô "hôm nay" thiếu hẳn buổi sáng, đều đặn, mãi mãi.
 *  3. **Đếm cả những màn hình không công khai.** Những con số ấy nằm sẵn
 *     trong bảng chờ ngày một màn hình được bật lại.
 *  4. **Bảng mọc thêm cột định danh.** Đó là ranh giới làm bảng này không
 *     phải dữ liệu cá nhân; xem ca cuối cùng.
 */
class LuotXemApiTest extends TestCase
{
    use RefreshDatabase;

    private function manHinhCongKhai(array $ghiDe = [], string $trangThaiOwner = 'active'): Screen
    {
        $owner = Owner::factory()->create(['status' => $trangThaiOwner]);

        $site = Site::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'active',
        ]);

        return Screen::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ], $ghiDe));
    }

    private function gui(string $slug): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v2/screens/{$slug}/luot-xem");
    }

    private function soLuot(Screen $screen, ?string $ngay = null): int
    {
        return (int) DB::table('luot_xem_ngay')
            ->where('screen_id', $screen->id)
            ->when($ngay !== null, fn ($q) => $q->where('ngay', $ngay))
            ->sum('so_luot');
    }

    // ── Đường ghi ──────────────────────────────────────────────────────────

    public function test_mot_luot_xem_duoc_ghi_va_tra_204_than_rong(): void
    {
        $screen = $this->manHinhCongKhai();

        $phanHoi = $this->gui($screen->slug);

        $phanHoi->assertNoContent();

        // Thân rỗng không phải chi tiết nhỏ: một phản hồi mang `so_luot` biến
        // đường công khai này thành chỗ đọc số liệu kinh doanh của sàn — vị
        // trí nào đang được quan tâm — bằng cách gửi vào một lượt.
        $this->assertSame('', $phanHoi->getContent(), 'Phản hồi có thân — có thứ để dò ở đây.');

        $this->assertSame(1, $this->soLuot($screen));
    }

    public function test_luot_thu_hai_CONG_THEM_chu_khong_ghi_de(): void
    {
        // Ca quan trọng nhất của tệp này. `upsert()` của Laravel sinh
        // `so_luot = VALUES(so_luot)` thay vì `so_luot + 1`, nên mỗi ô vĩnh
        // viễn bằng 1 và trông hoàn toàn bình thường: mọi vị trí "được xem
        // đúng một lượt". Không gì đỏ, và phép xếp hạng của lát 3 trả về một
        // thứ tự tuỳ ý.
        $screen = $this->manHinhCongKhai();

        $this->gui($screen->slug)->assertNoContent();
        $this->gui($screen->slug)->assertNoContent();
        $this->gui($screen->slug)->assertNoContent();

        $this->assertSame(3, $this->soLuot($screen), 'Ô đếm bị ghi đè thay vì cộng dồn.');
    }

    public function test_moi_man_hinh_mot_hang_moi_ngay(): void
    {
        $a = $this->manHinhCongKhai();
        $b = $this->manHinhCongKhai();

        $this->gui($a->slug);
        $this->gui($a->slug);
        $this->gui($b->slug);

        $this->assertSame(2, $this->soLuot($a));
        $this->assertSame(1, $this->soLuot($b));

        // Hai màn hình, một ngày ⇒ đúng hai hàng. Bảng này không được lớn
        // theo số lượt gửi: đó là thứ làm nó không thể thành nhật ký hành vi.
        $this->assertSame(2, DB::table('luot_xem_ngay')->count());
    }

    public function test_cot_ngay_theo_gio_viet_nam_khong_theo_utc(): void
    {
        // 17:30 UTC ngày 08/11 là 00:30 ngày 09/11 ở Việt Nam. Lấy theo UTC
        // thì lượt này rơi vào ngày 08 — tức bảy giờ đầu mỗi ngày ở Việt Nam
        // bị dồn vào ngày hôm trước, đều đặn, mãi mãi.
        Carbon::setTestNow('2026-11-08 17:30:00');

        try {
            $screen = $this->manHinhCongKhai();
            $this->gui($screen->slug)->assertNoContent();

            $this->assertSame(
                1,
                $this->soLuot($screen, '2026-11-09'),
                'Cột `ngay` không theo giờ Việt Nam — ô "hôm nay" sẽ thiếu hẳn buổi sáng.',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_hai_ngay_la_hai_hang(): void
    {
        $screen = $this->manHinhCongKhai();

        Carbon::setTestNow('2026-11-08 05:00:00');
        $this->gui($screen->slug);

        Carbon::setTestNow('2026-11-09 05:00:00');
        $this->gui($screen->slug);

        Carbon::setTestNow();

        $this->assertSame(1, $this->soLuot($screen, '2026-11-08'));
        $this->assertSame(1, $this->soLuot($screen, '2026-11-09'));
    }

    // ── Chỉ màn hình công khai ─────────────────────────────────────────────

    public function test_slug_khong_ton_tai_tra_404_va_khong_ghi_gi(): void
    {
        $this->gui('khong-co-vi-tri-nao-the-nay')
            ->assertStatus(404)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);

        $this->assertSame(0, DB::table('luot_xem_ngay')->count());
    }

    public function test_man_hinh_da_tat_khong_duoc_dem(): void
    {
        $screen = $this->manHinhCongKhai(['active' => false]);

        $this->gui($screen->slug)->assertStatus(404);

        $this->assertSame(0, DB::table('luot_xem_ngay')->count(), 'Dựng được số cho màn hình đã tắt.');
    }

    public function test_man_hinh_cua_owner_chua_duoc_duyet_khong_duoc_dem(): void
    {
        // `publiclyVisible()` là **hai** điều kiện: `screens.active` là công
        // tắc của chính media owner, còn `owners.status` là việc sàn đã duyệt
        // owner đó hay chưa. Chỉ kiểm `active` là đếm cho cả những owner chưa
        // được duyệt — và những con số ấy nằm sẵn trong bảng.
        $screen = $this->manHinhCongKhai([], 'pending');

        $this->gui($screen->slug)->assertStatus(404);

        $this->assertSame(0, DB::table('luot_xem_ngay')->count());
    }

    public function test_dem_duoc_qua_moi_dinh_danh_cong_khai(): void
    {
        // Trang chi tiết nhận cả bốn dạng định danh (`FrontpageService::
        // getScreenDetail`). Nếu đường đếm chỉ nhận `slug` thì những vị trí
        // người ta vào bằng `uuid` hoặc `external_id` có con số **vĩnh viễn
        // bằng 0**, trong khi trang vẫn mở bình thường.
        $screen = $this->manHinhCongKhai([
            'uuid'        => 'b8e9c4a0-1111-4222-8333-444455556666',
            'external_id' => 'KN-ADV-0042',
        ]);

        foreach ([$screen->slug, $screen->id, $screen->uuid, $screen->external_id] as $dinhDanh) {
            $this->gui((string) $dinhDanh)->assertNoContent();
        }

        $this->assertSame(4, $this->soLuot($screen));
    }

    // ── Hình dạng của bảng là một lời hứa ──────────────────────────────────

    public function test_than_request_khong_duoc_doc(): void
    {
        // Một client tương lai gửi kèm `user_id` hay `session_id` thì không
        // được có đường nào để nó đi vào. Hôm nay bảng không có cột nhận
        // chúng; ca này canh cả việc controller không bắt đầu đọc thân.
        $screen = $this->manHinhCongKhai();

        $this->postJson("/api/v2/screens/{$screen->slug}/luot-xem", [
            'user_id'    => 999,
            'session_id' => 'phien-nao-do',
            'referrer'   => 'https://google.com/search?q=bien+quang+cao',
        ])->assertNoContent();

        $hang = DB::table('luot_xem_ngay')->first();

        $this->assertSame(
            ['ngay', 'screen_id', 'so_luot'],
            array_keys((array) $hang),
            'Hàng lượt xem có thêm trường.',
        );
    }

    public function test_bang_khong_co_cot_nao_dinh_danh_nguoi_xem(): void
    {
        // Đây là ca canh một quyết định, không phải một phép kiểm hình thức.
        //
        // Bảng lượt xem **không phải dữ liệu cá nhân** vì nó gộp theo
        // `(ngày, màn hình)` và không có chỗ nào cho một người. Đó là thứ làm
        // câu hỏi "có phải xin đồng ý theo Nghị định 13/2023/NĐ-CP không"
        // không phát sinh — xem docblock migration `create_luot_xem_ngay_table`
        // và ranh giới đã viết ra từ `create_do_dac_ngay_table`.
        //
        // Ngày nào ca này bị sửa cho qua, tiền đề đó đã đổi, và nó cần người
        // có thẩm quyền pháp lý xem lại chứ không phải một lần sửa test.
        $this->assertSame(
            ['ngay', 'screen_id', 'so_luot'],
            Schema::getColumnListing('luot_xem_ngay'),
            'Bảng lượt xem mọc thêm cột. Nếu cột mới định danh được người xem thì bảng này đã '
                . 'thành một bảng hành vi, và nó cần đồng ý của người dùng cùng một mục trong '
                . 'chính sách bảo mật.',
        );
    }

    public function test_duong_ghi_co_gioi_han_tan_suat(): void
    {
        // CLAUDE.md mục 2: mọi endpoint có giới hạn tần suất. Đây là đường
        // GHI mở cho người lạ, nên thiếu trần là thiếu lớp chặn đầu tiên.
        // Kiểm khai báo chứ không gửi 61 lượt thật: cái cần canh là route có
        // mang throttle hay không, và 61 lượt ghi CSDL chỉ để đọc một con số
        // đã nằm trong khai báo.
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v2/screens/{slug}/luot-xem'
        );

        $this->assertNotNull($route, 'Không tìm thấy route đếm lượt xem.');
        $this->assertContains('throttle:60,60', $route->gatherMiddleware());
    }

    // ── Múi giờ sống ở một chỗ ─────────────────────────────────────────────

    public function test_mui_gio_khop_voi_may_dem_do_dac(): void
    {
        // Hai máy đếm phải gộp theo cùng một định nghĩa "ngày", nếu không thì
        // hai ô trên cùng một bảng điều khiển nói về hai cửa sổ khác nhau.
        $this->assertSame(
            \App\Services\DoDacService::MUI_GIO,
            LuotXemService::MUI_GIO,
        );
    }
}
