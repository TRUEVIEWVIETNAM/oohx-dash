<?php

namespace Tests\Feature\Api\V2;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `GET /api/v2/me` — người đang đăng nhập, cho thanh điều hướng của Next.js.
 *
 * Bốn điều test này canh:
 *
 *  1. **Không nằm sau middleware `buyer`.** Người đã đăng nhập mà chưa có tổ
 *     chức vẫn phải nhận 200 — họ vừa đăng ký. Đặt endpoint sau `buyer` là trả
 *     403 cho họ, và header hiện ra như thể họ chưa đăng nhập.
 *  2. **401 cho khách**, với envelope thống nhất. Client coi 401 là "khách".
 *  3. **DTO hẹp.** Đây là endpoint mọi trang gọi trên mọi lượt duyệt, nên mỗi
 *     trường thêm vào là một trường bị phát ra hàng nghìn lần cho một việc nó
 *     không phục vụ.
 *  4. **`initial` cắt theo ký tự, không theo byte.** Tên tiếng Việt có ký tự
 *     nhiều byte; cắt theo byte cho ra nửa ký tự và ô avatar hiện dấu hỏi.
 */
class MeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);
    }

    private function buyer(?Organization $org = null, string $name = 'Nguyễn Anh Tuấn'): User
    {
        $user = User::factory()->create([
            'name'                    => $name,
            'current_organization_id' => $org?->id,
        ]);
        $user->assignRole('buyer');

        if ($org) {
            OrganizationUser::create([
                'organization_id' => $org->id,
                'user_id'         => $user->id,
                'role'            => OrganizationUser::ROLE_ADMIN,
            ]);
        }

        return $user;
    }

    // ── Xác thực ────────────────────────────────────────────────────────────

    public function test_khach_nhan_401_voi_envelope_thong_nhat(): void
    {
        $this->getJson('/api/v2/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_nguoi_dung_chua_co_to_chuc_van_nhan_200(): void
    {
        // Lý do endpoint này tách khỏi nhóm có middleware `buyer`.
        //
        // Một người vừa đăng ký và chưa tạo tổ chức vẫn cần header vẽ đúng.
        // Nếu nó nằm sau `buyer` thì họ nhận 403, client coi là "khách", và
        // thanh điều hướng hiện nút Đăng nhập cho một người đang đăng nhập.
        $user = $this->buyer(null);

        $this->actingAs($user)
            ->getJson('/api/v2/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Nguyễn Anh Tuấn')
            ->assertJsonPath('data.organization', null);
    }

    // ── Nội dung ────────────────────────────────────────────────────────────

    public function test_tra_dung_nhung_gi_header_can_ve(): void
    {
        $org  = Organization::factory()->create(['status' => 'active', 'name' => 'Agency X']);
        $user = $this->buyer($org);

        $this->actingAs($user)
            ->getJson('/api/v2/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Nguyễn Anh Tuấn')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.organization.name', 'Agency X')
            ->assertJsonPath('data.cart_count', 0);
    }

    public function test_initial_cat_theo_ky_tu_khong_theo_byte(): void
    {
        $user = $this->buyer(null, 'Đặng Thị Hương');

        // "Đ" là hai byte trong UTF-8. `substr($name, 0, 1)` cho nửa ký tự, và
        // ô avatar hiện dấu hỏi — bản Blade dùng `mb_substr`, giữ nguyên.
        $this->actingAs($user)
            ->getJson('/api/v2/me')
            ->assertOk()
            ->assertJsonPath('data.initial', 'Đ');
    }

    public function test_cart_count_lay_tu_gio_that(): void
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = $this->buyer($org);

        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenSpec::factory()->create(['screen_id' => $screen->id]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        $carts = app(CartService::class);
        $cart  = $carts->getOrCreateCart($user);
        $start = now()->addYear()->startOfYear();
        $carts->addItem($cart, $screen->id, [
            'start_date'         => $start->toDateString(),
            'end_date'           => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
            'share_of_voice_pct' => 100,
        ]);

        // Cùng hàm header Blade gọi (`CartService::getItemCount`), nên hai bản
        // không thể lệch số.
        $this->actingAs($user)
            ->getJson('/api/v2/me')
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1);
    }

    // ── DTO hẹp ─────────────────────────────────────────────────────────────

    public function test_khong_lo_gi_ngoai_nhung_gi_header_ve(): void
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = $this->buyer($org);

        $response = $this->actingAs($user)->getJson('/api/v2/me')->assertOk();

        // Khoá đúng tập khoá, không chỉ kiểm vắng một vài trường: thêm trường
        // mới vào DTO này là mở rộng phạm vi của một endpoint mọi trang gọi
        // tới, nên nó phải là một quyết định có người đọc, không phải một dòng
        // lọt qua.
        $this->assertSame(
            ['name', 'email', 'initial', 'organization', 'cart_count'],
            array_keys($response->json('data')),
        );

        $body = $response->getContent();

        foreach ([
            'password',
            'remember_token',
            'email_verified_at',
            'current_organization_id',
            'two_factor',
            'roles',
            'permissions',
        ] as $camKy) {
            $this->assertStringNotContainsString($camKy, $body, "/me để lộ \"{$camKy}\".");
        }
    }

    public function test_to_chuc_chi_tra_ten_khong_tra_id(): void
    {
        $org  = Organization::factory()->create(['status' => 'active', 'name' => 'Agency X']);
        $user = $this->buyer($org);

        $response = $this->actingAs($user)->getJson('/api/v2/me')->assertOk();

        // Trả `id` ra là mời bên tiêu thụ dùng nó để gọi tiếp, và khi đó phạm
        // vi của endpoint này âm thầm rộng ra.
        $this->assertSame(['name'], array_keys($response->json('data.organization')));
        $this->assertStringNotContainsString($org->id, $response->getContent());
    }
}
