<?php

namespace Tests\Feature;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Nội dung quảng cáo: disk riêng và URL ký hạn.
 *
 * Tới 03/10/2026 `uploadCreative()` lưu vào disk `public`, nên tệp tải được
 * qua `/storage/creatives/...` **không cần đăng nhập**. Đường dẫn gồm id chiến
 * dịch và tên tệp băm nên khó đoán, nhưng khó đoán không phải phân quyền — và
 * CLAUDE.md mục 5 nêu đúng "nội dung quảng cáo" trong nhóm tệp nhạy cảm phải
 * để disk riêng, truy cập qua URL ký hạn.
 *
 * Những thứ test này canh:
 *
 *  1. Tệp **không** nằm trên disk công khai sau khi tải lên.
 *  2. Route phát tệp đòi **cả** chữ ký **và** quyền — bỏ lớp nào cũng mở đúng
 *     lỗ lớp đó đang bịt.
 *  3. Media owner chỉ thấy nội dung đã gán vào dòng **của họ**, không phải mọi
 *     nội dung trong chiến dịch.
 *  4. URL hết hạn thì không dùng được.
 */
class CreativePrivateDiskTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        Storage::fake('public');
        Storage::fake(config('creatives.disk'));

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = $this->makeMember($this->org, OrganizationUser::ROLE_ADMIN);
    }

    private function makeMember(Organization $org, string $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => $role,
        ]);

        return $user;
    }

    /**
     * Route Blade nằm trong nhóm `Route::domain($fpDomain)`, nên URL phải mang
     * đúng host đó — host mặc định của test là `localhost` và route sẽ không
     * khớp, trả 404 vì một lý do không liên quan gì tới điều đang kiểm.
     */
    private function uploadUrl(Campaign $campaign): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . '/booking/' . $campaign->id . '/creative';
    }

    private function campaign(string $status = Campaign::STATUS_DRAFT): Campaign
    {
        return Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now(),
            'end_date'        => now()->addMonth(),
            'status'          => $status,
        ]);
    }

    private function creativeWithFile(Campaign $campaign, string $noiDung = 'noi-dung-bi-mat'): Creative
    {
        $path = 'creatives/' . $campaign->id . '/' . Str::random(16) . '.png';
        Storage::disk(config('creatives.disk'))->put($path, $noiDung);

        return Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $campaign->organization_id,
            'name'            => 'Banner thử',
            'type'            => 'image',
            'file_path'       => $path,
            'file_size'       => strlen($noiDung),
            'status'          => 'pending_review',
        ]);
    }

    // ── Chỗ lưu ─────────────────────────────────────────────────────────────

    public function test_tai_len_khong_de_tep_tren_disk_cong_khai(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->buyer)->post($this->uploadUrl($campaign), [
            'file' => UploadedFile::fake()->image('banner.png', 400, 200),
        ])->assertRedirect();

        $creative = Creative::firstOrFail();

        Storage::disk(config('creatives.disk'))->assertExists($creative->file_path);

        // Phép kiểm cốt lõi của cả thay đổi này.
        Storage::disk('public')->assertMissing($creative->file_path);
    }

    public function test_kieu_tep_quyet_theo_mime_chu_khong_theo_ten(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->buyer)->post($this->uploadUrl($campaign), [
            'file' => UploadedFile::fake()->create('clip.mp4', 120),
        ])->assertRedirect();

        $this->assertSame('video', Creative::firstOrFail()->type);
    }

    public function test_dinh_dang_ngoai_danh_sach_bi_tu_choi(): void
    {
        $campaign = $this->campaign();

        // Lưu ý giới hạn của test này: tệp giả của Laravel báo mime SUY RA TỪ
        // TÊN (`Testing\File::getMimeType()`), nên nó không chứng minh được
        // rằng mime đọc từ nội dung. Nó chứng minh danh sách cho phép có hiệu
        // lực — phần đọc nội dung thật chỉ xảy ra với tệp thật.
        $this->actingAs($this->buyer)->post($this->uploadUrl($campaign), [
            'file' => UploadedFile::fake()->create('evil.exe', 10),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Creative::count());
    }

    // ── Route phát tệp: hai lớp ─────────────────────────────────────────────

    public function test_khong_co_chu_ky_thi_bi_tu_choi(): void
    {
        $creative = $this->creativeWithFile($this->campaign());

        // Không có chữ ký thì `/creatives/{id}/file` là một mặt tiền để thử
        // từng id.
        $this->actingAs($this->buyer)
            ->get('/creatives/' . $creative->id . '/file')
            ->assertStatus(403);
    }

    public function test_co_chu_ky_nhung_chua_dang_nhap_thi_khong_vao_duoc(): void
    {
        $creative = $this->creativeWithFile($this->campaign());

        $this->get($creative->file_url)->assertRedirect('/login');
    }

    public function test_nguoi_mua_cua_chien_dich_tai_duoc_tep(): void
    {
        $creative = $this->creativeWithFile($this->campaign(), 'noi-dung-that');

        $response = $this->actingAs($this->buyer)->get($creative->file_url)->assertOk();

        $this->assertSame('noi-dung-that', $response->streamedContent());

        // Không để proxy hay CDN giữ lại: URL đã ký hạn thì một bản sao trong
        // cache chung sẽ sống lâu hơn chính cái hạn đó.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_co_chu_ky_hop_le_nhung_khong_co_quyen_thi_van_bi_tu_choi(): void
    {
        $creative = $this->creativeWithFile($this->campaign());

        // URL ký hạn do người có quyền sinh ra, rồi rò sang người khác — lịch
        // sử trình duyệt, ảnh chụp màn hình chia sẻ lại. Chữ ký vẫn hợp lệ.
        $url = $creative->file_url;

        $nguoiLa = $this->makeMember(
            Organization::factory()->create(['status' => 'active']),
            OrganizationUser::ROLE_ADMIN,
        );

        $this->actingAs($nguoiLa)->get($url)->assertStatus(403);
    }

    public function test_url_het_han_thi_khong_dung_duoc(): void
    {
        $creative = $this->creativeWithFile($this->campaign());

        $url = URL::temporarySignedRoute(
            'creatives.file',
            now()->subMinute(),
            ['creative' => $creative->id],
        );

        $this->actingAs($this->buyer)->get($url)->assertStatus(403);
    }

    public function test_quan_tri_san_tai_duoc_tep(): void
    {
        $creative = $this->creativeWithFile($this->campaign());
        $url      = $creative->file_url;

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        // Duyệt nội dung là việc của quản trị sàn. Không có `Gate::before` nào
        // trong dự án này nên quyền đó phải khai trong `CreativePolicy`, không
        // tự có.
        $this->actingAs($admin)->get($url)->assertOk();
    }

    // ── Media owner: phạm vi hẹp ────────────────────────────────────────────

    /** @return array{0: User, 1: Owner} */
    private function ownerUser(): array
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $user  = User::factory()->create(['current_owner_id' => $owner->id]);

        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'owner',
        ]);

        return [$user, $owner];
    }

    private function lineFor(Campaign $campaign, Owner $owner): BookingLine
    {
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);

        return BookingLine::create([
            'campaign_id'    => $campaign->id,
            'screen_id'      => $screen->id,
            'owner_id'       => $owner->id,
            'start_date'     => now(),
            'end_date'       => now()->addMonth(),
            'status'         => 'approved',
            'estimated_cost' => 1_000_000,
        ]);
    }

    public function test_owner_thay_noi_dung_da_gan_vao_dong_cua_minh(): void
    {
        $campaign = $this->campaign();
        $creative = $this->creativeWithFile($campaign);
        $url      = $creative->file_url;

        [$ownerUser, $owner] = $this->ownerUser();
        $line = $this->lineFor($campaign, $owner);

        $creative->bookingLines()->attach($line->id);

        $this->actingAs($ownerUser)->get($url)->assertOk();
    }

    public function test_owner_khong_thay_noi_dung_chua_gan_vao_dong_cua_minh(): void
    {
        $campaign = $this->campaign();
        $creative = $this->creativeWithFile($campaign);
        $url      = $creative->file_url;

        [$ownerUser, $owner] = $this->ownerUser();

        // Owner CÓ dòng trong chiến dịch, nhưng nội dung này chưa gán vào dòng
        // nào của họ.
        //
        // Đây là chỗ `CampaignPolicy::viewAsOwner()` sẽ cho qua, và vì sao
        // `CreativePolicy` không dùng nó: một chiến dịch có thể có nội dung
        // riêng cho từng màn hình, và owner A thấy nội dung dành cho màn hình
        // của owner B là rò đúng kiểu Codex R01 đã mắc.
        $this->lineFor($campaign, $owner);

        $this->actingAs($ownerUser)->get($url)->assertStatus(403);
    }

    public function test_owner_la_hoan_toan_thi_khong_thay_gi(): void
    {
        $campaign = $this->campaign();
        $creative = $this->creativeWithFile($campaign);
        $url      = $creative->file_url;

        [$ownerUser] = $this->ownerUser();

        // Không có dòng nào trong chiến dịch.
        $this->actingAs($ownerUser)->get($url)->assertStatus(403);
    }

    // ── Bản ghi không có tệp ────────────────────────────────────────────────

    public function test_ban_ghi_khong_co_tep_thi_khong_sinh_url(): void
    {
        $creative = Creative::create([
            'campaign_id'     => $this->campaign()->id,
            'organization_id' => $this->org->id,
            'name'            => 'Chỉ có VAST tag',
            'type'            => 'image',
            'file_path'       => null,
            'status'          => 'pending_review',
        ]);

        $this->assertNull($creative->file_url);
    }

    public function test_tep_bien_mat_khoi_dia_thi_tra_404_chu_khong_500(): void
    {
        $creative = $this->creativeWithFile($this->campaign());
        $url      = $creative->file_url;

        Storage::disk(config('creatives.disk'))->delete($creative->file_path);

        $this->actingAs($this->buyer)->get($url)->assertStatus(404);
    }
}
