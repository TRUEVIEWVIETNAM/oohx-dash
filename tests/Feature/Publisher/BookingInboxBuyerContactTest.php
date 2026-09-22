<?php

namespace Tests\Feature\Publisher;

use App\Filament\Publisher\Resources\BookingInboxResource;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Media owner xem được liên hệ người mua trong Booking Inbox (phản hồi review
 * TMĐT vòng 2, comment 7): sàn cho hai bên liên hệ trực tiếp.
 *
 * Đây là dữ liệu cá nhân của người mua, nên ranh giới quan trọng hơn bản thân
 * khối hiển thị: owner KHÔNG có màn hình trong booking thì không được thấy.
 */
class BookingInboxBuyerContactTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private Owner $bookedOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);

        $org = Organization::create([
            'name'          => 'Agency Mặt Trời',
            'slug'          => 'agency-mat-troi-' . uniqid(),
            'type'          => 'agency',
            'billing_phone' => '0909123456',
            'billing_email' => 'ketoan@mattroi.vn',
        ]);
        $buyer = User::factory()->create([
            'name'  => 'Trần Thị Mua',
            'email' => 'mua@mattroi.vn',
        ]);

        $this->bookedOwner = Owner::factory()->create(['status' => 'active']);

        $this->campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $buyer->id,
            'code'            => 'CPN-' . uniqid(),
            'name'            => 'Chiến dịch Tết',
            'start_date'      => now()->addWeek(),
            'end_date'        => now()->addMonth(),
            'status'          => Campaign::STATUS_PENDING,
            'submitted_at'    => now(),
        ]);

        $site   = Site::factory()->create(['owner_id' => $this->bookedOwner->id]);
        $screen = Screen::factory()->create(['owner_id' => $this->bookedOwner->id, 'site_id' => $site->id]);
        BookingLine::create([
            'campaign_id'    => $this->campaign->id,
            'screen_id'      => $screen->id,
            'owner_id'       => $this->bookedOwner->id,
            'start_date'     => now()->addWeek(),
            'end_date'       => now()->addMonth(),
            'status'         => 'pending',
            'estimated_cost' => 10_000_000,
        ]);
    }

    private function publisherOf(Owner $owner): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $user;
    }

    private function viewUrl(): string
    {
        return BookingInboxResource::getUrl('view', ['record' => $this->campaign], panel: 'publisher');
    }

    public function test_owner_co_man_hinh_trong_booking_thay_lien_he_nguoi_mua(): void
    {
        $this->actingAs($this->publisherOf($this->bookedOwner))
            ->get($this->viewUrl())
            ->assertOk()
            ->assertSee('Liên hệ người mua')
            ->assertSee('Trần Thị Mua')
            ->assertSee('mua@mattroi.vn')
            ->assertSee('0909123456')
            ->assertSee('ketoan@mattroi.vn');
    }

    public function test_owner_khong_co_man_hinh_trong_booking_khong_mo_duoc_trang(): void
    {
        $outsider = Owner::factory()->create(['status' => 'active']);

        $response = $this->actingAs($this->publisherOf($outsider))->get($this->viewUrl());

        $response->assertNotFound();
        $response->assertDontSee('mua@mattroi.vn');
    }

    public function test_booking_con_nhap_chua_gui_thi_owner_khong_thay(): void
    {
        // Người mua chưa gửi booking thì chưa có sự đồng ý chia sẻ nào phát sinh.
        $this->campaign->update(['status' => Campaign::STATUS_DRAFT]);

        $this->actingAs($this->publisherOf($this->bookedOwner))
            ->get($this->viewUrl())
            ->assertNotFound();
    }
}
