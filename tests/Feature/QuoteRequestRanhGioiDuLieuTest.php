<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\QuoteRequest;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\QuoteRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Ranh giới dữ liệu của yêu cầu báo giá (lát 5).
 *
 * ══ Hai điều phải đúng, và cả hai đều im lặng khi sai ══
 *
 * 1. **Owner A không thấy phần của owner B.** Một yêu cầu hỏi 12 vị trí của
 *    3 owner là một hành động của người mua nhưng ba việc phải trả lời. Nếu
 *    owner A đọc được cả danh sách thì sàn vừa cho họ biết đối thủ đang được
 *    hỏi gì — và không có gì trên màn hình nói rằng điều đó đã xảy ra.
 *
 * 2. **Liên hệ người mua chỉ mở sau khi quản trị duyệt** (chủ dự án chốt
 *    19/10/2026). Mở sớm thì sàn tự biến mình thành danh bạ khách hàng miễn
 *    phí: owner nhận mười yêu cầu là có mười liên hệ, không cần trả lời cái
 *    nào.
 *
 * Phép kiểm đi qua **policy**, đúng lớp mà Filament hỏi. Kiểm ở resource là
 * kiểm bản sao: CLAUDE.md mục 4 nói quyền chỉ được định nghĩa một chỗ.
 */
class QuoteRequestRanhGioiDuLieuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    /** Một người dùng của owner, có quyền `manage_bookings`. */
    private function nguoiCuaOwner(Owner $owner): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);

        // Vai trò `manager` có `manage_bookings` theo `OwnerUser::PERMISSIONS`
        // — bảng đó là nguồn sự thật, không phải một danh sách quyền viết tay
        // ở đây. Bản đầu tôi dựng `role => 'owner_admin'` với một mảng
        // `permissions`; cột `role` là enum nên MySQL cắt cụt giá trị và test
        // đỏ với "Data truncated for column 'role'".
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'manager',
        ]);

        return $user;
    }

    /** Một yêu cầu hỏi cả hai owner. */
    private function yeuCauHaiOwner(Owner $a, Owner $b): QuoteRequest
    {
        $mA = $this->manHinhCua($a, 'Màn hình của A');
        $mB = $this->manHinhCua($b, 'Màn hình của B');

        $this->postJson('/api/v2/quote-requests', [
            'slugs'             => [$mA->slug, $mB->slug],
            'contact_name'      => 'Người Mua',
            'contact_email'     => 'mua@brand.vn',
            'contact_phone'     => '0901234567',
            'dong_y_chinh_sach' => true,
        ])->assertStatus(201);

        return QuoteRequest::first();
    }

    public function test_owner_chi_doc_duoc_dong_cua_minh(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $yeuCau = $this->yeuCauHaiOwner($a, $b);

        $this->assertCount(2, $yeuCau->items, 'Yêu cầu phải có cả hai vị trí.');

        $choA = app(QuoteRequestService::class)->choOwner($a->id)->get();

        $this->assertCount(1, $choA, 'Owner A phải thấy yêu cầu này.');

        $this->assertSame(
            ['Màn hình của A'],
            $choA->first()->items->pluck('screen_name')->all(),
            'Owner A đọc được vị trí của owner B. Đó là cho họ biết đối thủ '
                . 'đang được hỏi gì.',
        );
    }

    public function test_owner_khong_lien_quan_khong_thay_yeu_cau(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);
        $c = Owner::factory()->create(['status' => 'active']);

        $this->yeuCauHaiOwner($a, $b);

        $this->assertCount(
            0,
            app(QuoteRequestService::class)->choOwner($c->id)->get(),
            'Owner không có vị trí nào trong yêu cầu vẫn đọc được nó.',
        );
    }

    public function test_truoc_khi_duyet_owner_khong_xem_duoc_lien_he(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $yeuCau = $this->yeuCauHaiOwner($a, $b);
        $nguoi  = $this->nguoiCuaOwner($a);

        $this->assertTrue(
            $nguoi->can('view', $yeuCau),
            'Owner có vị trí trong yêu cầu thì phải xem được yêu cầu.',
        );

        $this->assertFalse(
            $nguoi->can('xemDuocLienHe', $yeuCau),
            'Liên hệ người mua mở trước khi quản trị duyệt.',
        );
    }

    public function test_sau_khi_duyet_owner_xem_duoc_lien_he(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $yeuCau = $this->yeuCauHaiOwner($a, $b);
        $nguoi  = $this->nguoiCuaOwner($a);

        app(QuoteRequestService::class)->duyet($yeuCau->fresh());

        $this->assertTrue($nguoi->can('xemDuocLienHe', $yeuCau->fresh()));
    }

    public function test_owner_khong_lien_quan_van_khong_thay_lien_he_sau_khi_duyet(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);
        $c = Owner::factory()->create(['status' => 'active']);

        $yeuCau = $this->yeuCauHaiOwner($a, $b);
        app(QuoteRequestService::class)->duyet($yeuCau->fresh());

        $nguoiC = $this->nguoiCuaOwner($c);

        $this->assertFalse(
            $nguoiC->can('xemDuocLienHe', $yeuCau->fresh()),
            'Duyệt mở liên hệ cho owner TRONG yêu cầu, không mở cho cả sàn.',
        );
    }

    public function test_chi_quan_tri_duyet_duoc(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $yeuCau = $this->yeuCauHaiOwner($a, $b);
        $nguoi  = $this->nguoiCuaOwner($a);

        $this->assertFalse(
            $nguoi->can('duyet', $yeuCau),
            'Media owner tự duyệt được yêu cầu của chính mình, tức tự mở dữ '
                . 'liệu cá nhân của người mua cho mình.',
        );
    }

    public function test_duyet_hai_lan_khong_doi_moc_mo_lien_he(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $dichVu = app(QuoteRequestService::class);
        $yeuCau = $this->yeuCauHaiOwner($a, $b);

        $lan1 = $dichVu->duyet($yeuCau->fresh())->approved_at;
        $lan2 = $dichVu->duyet($yeuCau->fresh())->approved_at;

        $this->assertEquals(
            $lan1,
            $lan2,
            'Một bản ghi nói "đã mở liên hệ lúc X" thì không được đổi X.',
        );
    }

    public function test_serialize_model_khong_keo_theo_lien_he(): void
    {
        $a = Owner::factory()->create(['status' => 'active']);
        $b = Owner::factory()->create(['status' => 'active']);

        $json = $this->yeuCauHaiOwner($a, $b)->toJson();

        foreach (['mua@brand.vn', '0901234567', 'Người Mua'] as $rieng) {
            $this->assertStringNotContainsString(
                $rieng,
                $json,
                'Một cú `->toJson()` ở bất kỳ đâu kéo theo dữ liệu cá nhân. '
                    . '`$hidden` của model là lớp chặn cuối cùng khi ai đó '
                    . 'quên viết DTO.',
            );
        }
    }
}
