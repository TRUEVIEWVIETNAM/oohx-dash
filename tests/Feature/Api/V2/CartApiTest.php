<?php

namespace Tests\Feature\Api\V2;

use App\Models\Cart;
use App\Models\CartItem;
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
 * Giai đoạn 5, mốc 3 — nhóm **cần quyền**: giỏ hàng.
 *
 * Đây là nhóm đầu tiên của `/api/v2` có ghi dữ liệu và chạm vào tiền, nên
 * những thứ test này canh khác hẳn nhóm đọc:
 *
 *  1. **Chưa đăng nhập thì không vào được**, và lỗi đúng định dạng thống nhất.
 *  2. **Giỏ của người khác không chạm tới được** — và trả 404 chứ không 403,
 *     vì 403 là xác nhận "dòng này có tồn tại".
 *  3. **Số tiền do máy chủ tính.** Gửi `estimated_cost` lên thì nó bị bỏ qua.
 *  4. **`rate_snapshot` không ra ngoài.**
 *  5. Trang Blade dùng chung luật kiểm và policy, nên không được vỡ.
 */
class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = $this->makeBuyer($this->org);
    }

    private function makeBuyer(Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        return $user;
    }

    private function screen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 63.17]);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'device_token' => 'bi-mat-cua-thiet-bi',
        ]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
            // Cần số thật để kiểm việc tính lại lượt hiển thị; để trống thì
            // mọi phép so sẽ là 0 với 0 và ca test xanh mà không đo gì.
            'weekly_impressions'     => 70_000,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    /** @return array{start_date: string, end_date: string} */
    private function dates(): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    private function addViaService(Screen $screen): CartItem
    {
        $cart = app(CartService::class)->getOrCreateCart($this->buyer);

        return app(CartService::class)->addItem($cart, $screen->id, $this->dates() + ['share_of_voice_pct' => 100]);
    }

    // ── Cần đăng nhập ───────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_vao_duoc(): void
    {
        $this->getJson('/api/v2/cart')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized')
            ->assertJsonPath('code', 401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_moi_thao_tac_ghi_deu_doi_dang_nhap(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);

        $this->postJson('/api/v2/cart/items', ['screen_slug' => $screen->slug] + $this->dates())->assertStatus(401);
        $this->patchJson('/api/v2/cart/items/' . $item->id, ['spot_length' => 20])->assertStatus(401);
        $this->deleteJson('/api/v2/cart/items/' . $item->id)->assertStatus(401);

        // Và không thao tác nào trong số đó được thực hiện.
        $this->assertSame(1, CartItem::count());
    }

    public function test_401_cua_v1_khong_doi_dinh_dang(): void
    {
        // v1 là hợp đồng đang chạy với đối tác. Envelope mới chỉ áp cho v2.
        $this->getJson('/api/v1/inventory/screens')
            ->assertStatus(401)
            ->assertJsonMissingPath('code')
            ->assertJsonMissingPath('details');
    }

    // ── Thêm vào giỏ bằng slug ──────────────────────────────────────────────

    public function test_them_man_hinh_bang_slug(): void
    {
        $screen = $this->screen();

        $response = $this->actingAs($this->buyer)
            ->postJson('/api/v2/cart/items', ['screen_slug' => $screen->slug] + $this->dates())
            ->assertStatus(201);

        $this->assertSame(1, $response->json('data.summary.item_count'));
        $this->assertSame($screen->slug, $response->json('data.items.0.screen.slug'));
        $this->assertNotNull($response->json('data.added_item_id'));
        $this->assertSame(1, CartItem::count());
    }

    public function test_slug_khong_ton_tai_thi_422_chu_khong_im_lang(): void
    {
        $this->actingAs($this->buyer)
            ->postJson('/api/v2/cart/items', ['screen_slug' => 'khong-co-man-hinh-nay'] + $this->dates())
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);

        $this->assertSame(0, CartItem::count());
    }

    public function test_man_hinh_khong_cong_khai_thi_khong_them_duoc(): void
    {
        $screen = $this->screen();
        $screen->update(['active' => false]);

        // Quy đổi slug đi qua `publiclyVisible()`, nên màn hình đã tắt không
        // có đường vào giỏ kể cả khi client biết slug.
        $this->actingAs($this->buyer)
            ->postJson('/api/v2/cart/items', ['screen_slug' => $screen->slug] + $this->dates())
            ->assertStatus(422);

        $this->assertSame(0, CartItem::count());
    }

    // ── Số tiền do máy chủ tính ─────────────────────────────────────────────

    public function test_khong_nhan_so_tien_tu_client_khi_them(): void
    {
        $screen = $this->screen();

        $response = $this->actingAs($this->buyer)
            ->postJson('/api/v2/cart/items', [
                'screen_slug'     => $screen->slug,
                // Hai trường này không có trong luật kiểm và không được phép
                // có tác dụng nào.
                'estimated_cost'  => 1,
                'unit_price'      => 1,
            ] + $this->dates())
            ->assertStatus(201);

        $cost = $response->json('data.items.0.estimate.cost');

        $this->assertNotSame(1, $cost, 'Số tiền client gửi lên đã được dùng.');
        $this->assertGreaterThan(0, $cost);
        $this->assertSame($cost, (int) round((float) CartItem::first()->estimated_cost));
    }

    public function test_khong_nhan_so_tien_tu_client_khi_sua(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);
        $before = (int) round((float) $item->estimated_cost);

        $this->actingAs($this->buyer)
            ->patchJson('/api/v2/cart/items/' . $item->id, ['estimated_cost' => 1])
            ->assertOk();

        $this->assertSame($before, (int) round((float) $item->fresh()->estimated_cost));
    }

    public function test_keo_dai_ky_thi_may_chu_tinh_lai_tien(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);
        $before = (int) round((float) $item->estimated_cost);

        $this->assertSame(1_000_000, $before, 'Một kỳ tháng = io_rate.');

        // Với giá theo kỳ (`io`), tiền = io_rate × số kỳ, **không** phụ thuộc
        // tỷ lệ thời lượng, và một kỳ dở dang vẫn tính tròn một kỳ. Nên muốn
        // chứng minh máy chủ tính lại thì phải vượt qua mốc kỳ, không phải
        // rút ngắn trong cùng một kỳ.
        //
        // Lần đầu tôi viết ca này là rút từ một tháng xuống bảy ngày và tưởng
        // tiền phải giảm. CI đỏ, và nó đỏ đúng: giả định của tôi sai, không
        // phải code sai.
        $start = now()->addYear()->startOfYear();

        $response = $this->actingAs($this->buyer)
            ->patchJson('/api/v2/cart/items/' . $item->id, [
                'end_date' => $start->copy()->addMonthsNoOverflow(2)->subDay()->toDateString(),
            ])
            ->assertOk();

        $after = $response->json('data.items.0.estimate.cost');

        $this->assertSame(2_000_000, $after, 'Hai kỳ tháng phải là hai lần io_rate.');
        $this->assertSame($after, (int) round((float) $item->fresh()->estimated_cost));
    }

    public function test_sua_suat_phat_song_thi_may_chu_tinh_lai_luot_hien_thi(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);
        $before = (int) $item->estimated_impressions;

        $this->assertGreaterThan(0, $before, 'Ca test chưa dựng đúng tình huống: màn hình không có lượt hiển thị nào.');

        $response = $this->actingAs($this->buyer)
            ->patchJson('/api/v2/cart/items/' . $item->id, ['share_of_voice_pct' => 50])
            ->assertOk();

        $after = $response->json('data.items.0.estimate.impressions');

        $this->assertSame((int) round($before / 2), $after, 'Giảm suất một nửa mà lượt hiển thị không đổi theo.');
    }

    // ── Giỏ của người khác ──────────────────────────────────────────────────

    public function test_khong_sua_duoc_dong_gio_cua_nguoi_khac(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);

        $nguoiKhac = $this->makeBuyer($this->org);
        $truoc     = (int) $item->spot_length;

        // 404 chứ không 403: 403 là xác nhận "dòng này có tồn tại, chỉ là
        // không phải của bạn".
        $this->actingAs($nguoiKhac)
            ->patchJson('/api/v2/cart/items/' . $item->id, ['spot_length' => 30])
            ->assertStatus(404);

        $this->assertSame($truoc, (int) $item->fresh()->spot_length);
    }

    public function test_khong_xoa_duoc_dong_gio_cua_nguoi_khac(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);

        $this->actingAs($this->makeBuyer($this->org))
            ->deleteJson('/api/v2/cart/items/' . $item->id)
            ->assertStatus(404);

        $this->assertSame(1, CartItem::count());
    }

    public function test_cung_to_chuc_cung_khong_dung_chung_gio(): void
    {
        // Giỏ là bản nháp của từng người, không phải đơn hàng của tổ chức.
        // Hai người sửa chung một bản nháp sẽ ghi đè nhau mà không ai thấy.
        $screen = $this->screen();
        $this->addViaService($screen);

        $this->actingAs($this->makeBuyer($this->org))
            ->getJson('/api/v2/cart')
            ->assertOk()
            ->assertJsonPath('data.summary.item_count', 0);
    }

    // ── Danh sách trắng ─────────────────────────────────────────────────────

    public function test_khong_lo_rate_snapshot_va_truong_nhay_cam(): void
    {
        $screen = $this->screen();
        $this->addViaService($screen);

        $body = $this->actingAs($this->buyer)->getJson('/api/v2/cart')->assertOk()->getContent();

        foreach (['rate_snapshot', 'device_token', 'bi-mat-cua-thiet-bi', 'revenue_share_pct', '63.17'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "Giỏ hàng để lộ \"{$forbidden}\".");
        }
    }

    public function test_tong_gio_la_con_so_cua_may_chu(): void
    {
        $a = $this->screen();
        $b = $this->screen();
        $this->addViaService($a);
        $this->addViaService($b);

        $response = $this->actingAs($this->buyer)->getJson('/api/v2/cart')->assertOk();

        $expected = (int) round((float) CartItem::sum('estimated_cost'));

        $this->assertSame(2, $response->json('data.summary.item_count'));
        $this->assertSame($expected, $response->json('data.summary.subtotal'));
        $this->assertSame('VND', $response->json('data.summary.currency'));
    }

    // ── Xóa ─────────────────────────────────────────────────────────────────

    public function test_xoa_dong_gio_cua_chinh_minh(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);

        $this->actingAs($this->buyer)
            ->deleteJson('/api/v2/cart/items/' . $item->id)
            ->assertOk()
            ->assertJsonPath('data.summary.item_count', 0);

        $this->assertSame(0, CartItem::count());
    }

    // ── Trang Blade dùng chung luật kiểm và policy, không được vỡ ───────────

    public function test_trang_gio_blade_van_them_duoc_bang_id(): void
    {
        $screen = $this->screen();

        $this->actingAs($this->buyer)
            ->post('http://' . config('domains.frontpage', 'oohx.net') . '/cart/add', [
                'screen_id' => $screen->id,
            ] + $this->dates())
            ->assertRedirect();

        $this->assertSame(1, CartItem::count());
    }

    public function test_trang_gio_blade_van_chan_dong_cua_nguoi_khac(): void
    {
        $screen = $this->screen();
        $item   = $this->addViaService($screen);

        // Trang Blade giữ 403 — đó là hành vi sẵn có của nó, và người dùng
        // trang web không dò khóa bằng cách gõ URL như client API.
        $this->actingAs($this->makeBuyer($this->org))
            ->delete('http://' . config('domains.frontpage', 'oohx.net') . '/cart/' . $item->id)
            ->assertStatus(403);

        $this->assertSame(1, CartItem::count());
    }

    // ── VAT: một chỗ tính, một chỗ duy nhất ─────────────────────────────────

    public function test_vat_va_tong_cong_lay_tu_withVat_chu_khong_nhan_tay(): void
    {
        $this->addViaService($this->screen());
        $this->addViaService($this->screen());

        $response = $this->actingAs($this->buyer)->getJson('/api/v2/cart')->assertOk();

        $subtotal = $response->json('data.summary.subtotal');
        $vat      = $response->json('data.summary.vat');
        $total    = $response->json('data.summary.total');

        // Nguồn sự thật là service, không phải một phép nhân viết lại ở test.
        // Viết lại công thức ở đây là tạo chỗ làm tròn thứ ba, và khi ấy test
        // xanh không còn chứng minh được điều nó định chứng minh.
        $this->assertSame(
            app(\App\Services\PaymentService::class)->withVat($subtotal),
            $total,
            'Tổng cộng không khớp PaymentService::withVat().'
        );

        // Bất biến người mua đọc được trên trang: cột cộng phải cộng đúng.
        $this->assertSame($subtotal + $vat, $total, 'subtotal + vat không bằng total.');

        $this->assertIsInt($vat);
        $this->assertIsInt($total);
    }

    public function test_vat_khong_lech_1_dong_so_voi_cach_view_cu_tinh(): void
    {
        $this->addViaService($this->screen());

        $response = $this->actingAs($this->buyer)->getJson('/api/v2/cart')->assertOk();
        $total    = $response->json('data.summary.total');

        // Cách view CŨ: nhân trên tổng dạng float rồi mới làm tròn khi in.
        // Cách đúng: chốt tổng về int TRƯỚC, rồi mới cộng VAT.
        //
        // Ca này không đòi hai cách bằng nhau — chúng lệch được 1₫ và đó
        // chính là lý do bỏ cách cũ. Nó đòi con số API trả ra đúng bằng cách
        // ĐÚNG, tức không ai lặng lẽ trả lại cách cũ.
        $rate     = (float) config('pricing.vat_rate');
        $sumFloat = (float) CartItem::sum('estimated_cost');

        $this->assertSame(
            (int) round(((int) round($sumFloat)) * (1 + $rate)),
            $total,
            'Tổng cộng đang tính theo tổng float, không theo tổng đã chốt về int.'
        );
    }

    public function test_don_gia_va_so_man_hinh_ra_ngoai(): void
    {
        $item = $this->addViaService($this->screen());

        $response = $this->actingAs($this->buyer)->getJson('/api/v2/cart')->assertOk();

        // Thiếu hai trường này thì trang giỏ chỉ còn `estimate.cost` và không
        // giải thích được con số đó ra từ đâu — người mua thấy một tổng tiền
        // không có cách nào kiểm.
        $this->assertSame(
            (int) round((float) $item->unit_price),
            $response->json('data.items.0.estimate.unit_price')
        );
        $this->assertSame(
            $item->screen_count !== null ? (int) $item->screen_count : null,
            $response->json('data.items.0.delivery.screen_count')
        );
    }

    // ── Trang Blade giờ đọc qua API ─────────────────────────────────────────

    public function test_trang_gio_blade_doc_qua_api_chu_khong_dung_san_so_tien(): void
    {
        $this->addViaService($this->screen());

        $html = $this->actingAs($this->buyer)
            ->get('http://' . config('domains.frontpage', 'oohx.net') . '/cart')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/api/v2/cart', $html, 'Trang giỏ không trỏ tới endpoint v2.');

        // Chốt chặn hồi quy cho đúng lỗi vừa sửa: nếu ai dựng lại dòng VAT
        // bằng PHP thì con số sẽ nằm sẵn trong HTML, và chỗ làm tròn thứ hai
        // quay lại mà không ai thấy.
        $cost = (int) round((float) CartItem::sum('estimated_cost'));
        $this->assertStringNotContainsString(
            number_format((int) round($cost * (1 + (float) config('pricing.vat_rate'))), 0, ',', '.'),
            $html,
            'Trang giỏ lại tự dựng số tiền trong HTML thay vì lấy từ API.'
        );
    }

    public function test_chi_xem_trang_gio_thi_khong_tao_gio(): void
    {
        $this->assertSame(0, Cart::count());

        $this->actingAs($this->buyer)
            ->get('http://' . config('domains.frontpage', 'oohx.net') . '/cart')
            ->assertOk();

        // Action Blade không còn gọi `getOrCreateCart()`. Gọi nó ở cả hai nơi
        // là hai lần ghi cho một lần xem trang, và nó che mất lỗi: API hỏng mà
        // giỏ vẫn được tạo thì triệu chứng hiện ra ở chỗ khác chỗ hỏng.
        $this->assertSame(0, Cart::count(), 'Chỉ xem trang mà đã tạo giỏ.');
    }
}
