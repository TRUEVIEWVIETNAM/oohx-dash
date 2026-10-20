<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteRequestResource as AdminQuoteRequestResource;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\QuoteRequest;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\QuoteRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Xoá yêu cầu báo giá — chủ dự án chốt 20/10/2026.
 *
 * Lát 5 đóng cứng `delete`. Hệ quả: không ai thử được đường gửi thật trên
 * production, vì bản ghi thử sẽ nằm vĩnh viễn trong danh sách việc của media
 * owner. Mở quyền này là mở một đường **phá dữ liệu**, nên những điều dưới
 * đây phải đúng — và cả bốn đều im lặng khi sai:
 *
 * 1. **Chỉ quản trị sàn.** Owner thấy phần của mình nhưng xoá thì xoá cả bản
 *    ghi, tức xoá luôn phần của owner khác.
 * 2. **Xoá là xoá thật**, hàng vị trí đi theo. Một bản ghi cha mất mà hàng
 *    con còn lại là rác không ai nhìn thấy nữa.
 * 3. **Không chạm màn hình.** `screen_id` là `nullOnDelete` theo chiều ngược;
 *    xoá yêu cầu không được gỡ vị trí nào khỏi sàn.
 * 4. **Nhật ký không mang dữ liệu cá nhân.** Xoá bản ghi vì nó chứa tên,
 *    email, điện thoại rồi chép chính ba thứ đó sang tệp log là xoá trên
 *    giấy — và tệp log đi qua nhiều tay hơn cơ sở dữ liệu.
 */
class QuoteRequestXoaTest extends TestCase
{
    use RefreshDatabase;

    /** Dữ liệu cá nhân dùng chung cho mọi ca, để khẳng định phủ định tìm được. */
    private const TEN   = 'Người Mua Thử';
    private const EMAIL = 'mua-thu@brand.vn';
    private const DIEN_THOAI = '0901234567';

    /** @var array<int, array<string, mixed>> context của mọi dòng nhật ký xoá. */
    private array $nhatKy = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    private function manHinhCua(Owner $owner, string $ten): Screen
    {
        $site = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
        ]);

        return Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
            'name'     => $ten,
        ]);
    }

    private function nguoiCuaOwner(Owner $owner): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);

        // `manager` là vai có `manage_bookings` theo `OwnerUser::PERMISSIONS`.
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'manager',
        ]);

        return $user;
    }

    private function quanTri(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    /**
     * Một yêu cầu thật, gửi qua đúng đường công khai.
     *
     * Không dùng factory dựng tay: ca test phải chạy trên cùng bản ghi mà
     * `POST /api/v2/quote-requests` sinh ra, nếu không nó canh một hình dạng
     * dữ liệu không tồn tại trên production.
     *
     * @return array{0: QuoteRequest, 1: Screen, 2: Screen}
     */
    private function yeuCauHaiOwner(Owner $a, Owner $b): array
    {
        $mA = $this->manHinhCua($a, 'Màn hình của A');
        $mB = $this->manHinhCua($b, 'Màn hình của B');

        $this->postJson('/api/v2/quote-requests', [
            'slugs'             => [$mA->slug, $mB->slug],
            'contact_name'      => self::TEN,
            'contact_email'     => self::EMAIL,
            'contact_phone'     => self::DIEN_THOAI,
            'dong_y_chinh_sach' => true,
        ])->assertStatus(201);

        return [QuoteRequest::first(), $mA, $mB];
    }

    // ── 1. Ai xoá được ───────────────────────────────────────────────────────

    public function test_quan_tri_san_xoa_duoc(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $this->assertTrue($this->quanTri()->can('delete', $yeuCau));
    }

    public function test_media_owner_khong_xoa_duoc_du_co_vi_tri_trong_yeu_cau(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        [$yeuCau] = $this->yeuCauHaiOwner($a, $b);
        $nguoi    = $this->nguoiCuaOwner($a);

        $this->assertTrue(
            $nguoi->can('view', $yeuCau),
            'Owner A phải xem được yêu cầu — nếu không, ca dưới đúng vì lý do sai.',
        );

        $this->assertFalse(
            $nguoi->can('delete', $yeuCau),
            'Owner A xoá được một bản ghi còn chứa phần của owner B.',
        );
    }

    public function test_nguoi_dung_thuong_khong_xoa_duoc(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $this->assertFalse(User::factory()->create()->can('delete', $yeuCau));
    }

    // ── 2. Xoá là xoá thật ───────────────────────────────────────────────────

    public function test_xoa_go_ca_hang_vi_tri(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $id = $yeuCau->id;

        $this->assertDatabaseCount('quote_request_items', 2);

        app(QuoteRequestService::class)->xoa($yeuCau, $this->quanTri()->id, 'bản ghi thử');

        $this->assertDatabaseMissing('quote_requests', ['id' => $id]);

        $this->assertDatabaseMissing(
            'quote_request_items',
            ['quote_request_id' => $id],
            // Hàng con còn lại là rác không đường nào đọc tới: `/publisher`
            // lọc qua `whereHas('items')` của bản ghi cha đã mất.
        );
    }

    public function test_xoa_khong_cham_man_hinh_nao(): void
    {
        [$yeuCau, $mA, $mB] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        app(QuoteRequestService::class)->xoa($yeuCau, $this->quanTri()->id, 'bản ghi thử');

        foreach ([$mA, $mB] as $man) {
            $this->assertDatabaseHas('screens', ['id' => $man->id]);
        }
    }

    public function test_xoa_mot_yeu_cau_khong_cham_yeu_cau_khac(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        [$mot] = $this->yeuCauHaiOwner($a, $b);

        // Yêu cầu thứ hai, trên cùng hai owner.
        $m = $this->manHinhCua($a, 'Màn hình thứ hai của A');

        $this->postJson('/api/v2/quote-requests', [
            'slugs'             => [$m->slug],
            'contact_name'      => 'Người Mua Khác',
            'contact_email'     => 'khac@brand.vn',
            'dong_y_chinh_sach' => true,
        ])->assertStatus(201);

        $hai = QuoteRequest::where('id', '!=', $mot->id)->firstOrFail();

        app(QuoteRequestService::class)->xoa($mot, $this->quanTri()->id, 'bản ghi thử');

        $this->assertDatabaseHas('quote_requests', ['id' => $hai->id]);
        $this->assertDatabaseCount('quote_request_items', 1);
    }

    // ── 3. Nút ở `/admin` ────────────────────────────────────────────────────

    /**
     * Hành động riêng của panel admin, lấy qua reflection.
     *
     * `table()` cần một component Livewire để dựng, nhưng `hanhDongThem()`
     * trả thẳng mảng Action — đủ để canh "nút có tên gì" và "ai thấy nó".
     *
     * @return array<string, \Filament\Tables\Actions\Action>
     */
    private function hanhDongAdmin(): array
    {
        $ham = new \ReflectionMethod(AdminQuoteRequestResource::class, 'hanhDongThem');
        $ham->setAccessible(true);

        $theoTen = [];

        foreach ($ham->invoke(null) as $hanhDong) {
            $theoTen[$hanhDong->getName()] = $hanhDong;
        }

        return $theoTen;
    }

    public function test_quan_tri_thay_nut_xoa(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $this->actingAs($this->quanTri());

        $nut = $this->hanhDongAdmin();

        $this->assertArrayHasKey('xoa', $nut, 'Panel admin không có nút xoá nào.');

        $this->assertTrue(
            $nut['xoa']->record($yeuCau)->isVisible(),
            'Quyền đã mở nhưng nút vẫn ẩn — tính năng không có đường chạy '
                . 'thật từ giao diện tới CSDL (CLAUDE.md mục 8).',
        );
    }

    public function test_media_owner_khong_thay_nut_xoa(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);

        [$yeuCau] = $this->yeuCauHaiOwner($a, Owner::factory()->create(['status' => 'active']));

        $this->actingAs($this->nguoiCuaOwner($a));

        $this->assertFalse(
            $this->hanhDongAdmin()['xoa']->record($yeuCau)->isVisible(),
            'Nút xoá hiện với người không có quyền xoá.',
        );
    }

    /**
     * Hộp thoại đòi gõ lại mã — phép so sánh đó là lớp chắn duy nhất giữa
     * một cú bấm theo quán tính và một bản ghi mất hẳn.
     */
    public function test_phai_go_dung_ma_moi_xoa_duoc(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $ma = $yeuCau->code;

        $this->assertTrue(AdminQuoteRequestResource::maKhop($ma, $yeuCau));
        $this->assertTrue(
            AdminQuoteRequestResource::maKhop('  ' . $ma . ' ', $yeuCau),
            'Dán mã kèm khoảng trắng là chuyện thường.',
        );

        foreach ([null, '', 'YCB', strtolower($ma), substr($ma, 0, -1), $ma . '1'] as $sai) {
            $this->assertFalse(
                AdminQuoteRequestResource::maKhop($sai, $yeuCau),
                'Mã sai vẫn xoá được: ' . var_export($sai, true),
            );
        }
    }

    // ── 4. Nhật ký ───────────────────────────────────────────────────────────

    public function test_nhat_ky_ghi_ai_vi_sao_va_xoa_cai_gi(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $ma      = $yeuCau->code;
        $quanTri = $this->quanTri();

        $this->batNhatKy();

        app(QuoteRequestService::class)->xoa($yeuCau, $quanTri->id, 'kiểm tra đường gửi trên production');

        $this->assertCount(1, $this->nhatKy, 'Xoá mà không để lại dòng nhật ký nào (CLAUDE.md mục 5).');

        $ctx = $this->nhatKy[0];

        $this->assertSame($ma, $ctx['code']);
        $this->assertSame($quanTri->id, $ctx['user_id']);
        $this->assertSame('kiểm tra đường gửi trên production', $ctx['ly_do']);
        $this->assertSame(2, $ctx['so_muc']);
        $this->assertCount(2, $ctx['owner_ids'], 'Phải ghi owner nào mất đầu mối.');
        $this->assertFalse($ctx['da_duyet']);
    }

    public function test_nhat_ky_khong_mang_du_lieu_ca_nhan(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $this->batNhatKy();

        app(QuoteRequestService::class)->xoa($yeuCau, $this->quanTri()->id, 'bản ghi thử');

        $json = json_encode($this->nhatKy, JSON_UNESCAPED_UNICODE);

        foreach ([self::TEN, self::EMAIL, self::DIEN_THOAI] as $rieng) {
            $this->assertStringNotContainsString(
                $rieng,
                (string) $json,
                'Xoá bản ghi vì nó chứa dữ liệu cá nhân rồi chép chính dữ liệu '
                    . 'đó sang tệp log là xoá trên giấy.',
            );
        }
    }

    public function test_nhat_ky_doc_duoc_so_muc_sau_khi_ban_ghi_da_mat(): void
    {
        [$yeuCau] = $this->yeuCauHaiOwner(
            Owner::factory()->create(['status' => 'active']),
            Owner::factory()->create(['status' => 'active']),
        );

        $this->batNhatKy();

        app(QuoteRequestService::class)->xoa($yeuCau, $this->quanTri()->id, 'bản ghi thử');

        // Ảnh chụp phải lấy TRƯỚC `delete()`. Nếu ai đó dời đoạn chụp xuống
        // sau, `so_muc` thành 0 và `owner_ids` thành mảng rỗng — dòng nhật ký
        // vẫn ghi, vẫn trông bình thường, và không còn nói được đã xoá cái gì.
        $this->assertGreaterThan(
            0,
            $this->nhatKy[0]['so_muc'],
            'Ảnh chụp cho nhật ký bị lấy sau khi xoá: không còn gì để đọc.',
        );
    }

    /**
     * Thu lại context của mọi `Log::info('Xoá yêu cầu báo giá', …)`.
     *
     * `Log::spy()` của Mockery đòi khẳng định theo kiểu kỳ vọng; ở đây cần
     * **đọc** nội dung đã ghi để kiểm cả thứ không được có, nên bắt bằng một
     * listener là trực tiếp hơn.
     *
     * Ghi vào thuộc tính chứ không trả mảng ra: `return $ghi` trả một **bản
     * sao** của mảng lúc chưa có gì, và mọi khẳng định sau đó đọc mảng rỗng
     * rồi đỏ với lý do không liên quan.
     */
    private function batNhatKy(): void
    {
        $this->nhatKy = [];

        Log::listen(function ($su): void {
            if ($su->message === 'Xoá yêu cầu báo giá') {
                $this->nhatKy[] = $su->context;
            }
        });
    }
}
