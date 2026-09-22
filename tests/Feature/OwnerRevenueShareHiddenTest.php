<?php

namespace Tests\Feature;

use App\Filament\Resources\OwnerResource;
use App\Models\Owner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin không còn hiện tỷ lệ chia doanh thu của media owner (phản hồi review
 * TMĐT vòng 2, mục 16).
 *
 * Hồ sơ khai sàn thu phí thuê bao và không chia lợi nhuận giao dịch, nhưng ảnh
 * chụp màn Media Owners gửi kèm lại có cột "Rev Share 70.00%" — tự mâu thuẫn
 * ngay trong hồ sơ.
 */
class OwnerRevenueShareHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->owner = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 70]);
    }

    public function test_danh_sach_media_owner_khong_co_cot_rev_share(): void
    {
        $this->actingAs($this->admin)
            ->get(OwnerResource::getUrl('index', panel: 'admin'))
            ->assertOk()
            ->assertSee($this->owner->name)
            ->assertDontSee('Rev Share')
            ->assertDontSee('70.00%');
    }

    public function test_form_media_owner_khong_co_truong_revenue_share(): void
    {
        $this->actingAs($this->admin)
            ->get(OwnerResource::getUrl('edit', ['record' => $this->owner], panel: 'admin'))
            ->assertOk()
            // Kiểm tra chữ hiển thị: snapshot Livewire vẫn mang theo thuộc tính
            // của record trong HTML, nhưng không có trường nào render ra nó và
            // Filament chỉ lưu các trường có trong schema.
            ->assertDontSeeText('Revenue');
    }
}
