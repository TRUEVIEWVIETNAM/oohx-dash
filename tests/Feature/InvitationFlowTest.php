<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;
    private User $inviter;
    private UserInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->owner   = Owner::factory()->create(['status' => 'active']);
        $this->inviter = User::factory()->create();
        OwnerUser::create([
            'owner_id' => $this->owner->id,
            'user_id'  => $this->inviter->id,
            'role'     => 'owner',
        ]);

        $this->service = app(UserInvitationService::class);
    }

    public function test_invite_creates_row_and_sends_notification(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            email:             'newbie@example.com',
            tenantType:        UserInvitation::TENANT_OWNER,
            tenantId:          $this->owner->id,
            role:              'manager',
            allowedNetworkIds: null,
            invitedBy:         $this->inviter,
        );

        $this->assertNotNull($invitation->token);
        $this->assertSame('newbie@example.com', $invitation->email);
        $this->assertSame($this->owner->id, $invitation->tenant_id);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->expires_at->isFuture());

        Notification::assertSentOnDemand(UserInvitationNotification::class, function ($n, $channels, $notifiable) {
            // Notification::route('mail', $email) lưu routes['mail'] là CHUỖI chứ
            // không phải mảng, nên in_array() ném TypeError. Ép kiểu ở phía test,
            // không đổi cách gửi thông báo.
            return in_array('newbie@example.com', (array) ($notifiable->routes['mail'] ?? []), true);
        });
    }

    public function test_invite_revokes_pending_duplicate_for_same_tenant(): void
    {
        Notification::fake();

        $first = $this->service->invite(
            'dup@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'read_only', null, $this->inviter,
        );
        $second = $this->service->invite(
            'dup@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'manager', null, $this->inviter,
        );

        $this->assertDatabaseMissing('user_invitations', ['id' => $first->id]);
        $this->assertDatabaseHas('user_invitations', ['id' => $second->id, 'role' => 'manager']);
    }

    public function test_invite_blocks_existing_member(): void
    {
        Notification::fake();

        $existing = User::factory()->create(['email' => 'member@example.com']);
        OwnerUser::create([
            'owner_id' => $this->owner->id,
            'user_id'  => $existing->id,
            'role'     => 'manager',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->invite(
            'member@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'read_only', null, $this->inviter,
        );
    }

    public function test_accept_creates_user_with_pivot_and_role(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'fresh@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'manager', null, $this->inviter,
        );

        $user = $this->service->accept($invitation->token, 'Tên Mới', 'super-secret-password');

        $this->assertSame('fresh@example.com', $user->email);
        $this->assertSame('Tên Mới', $user->name);
        $this->assertTrue($user->hasRole('publisher'));
        $this->assertSame($this->owner->id, $user->current_owner_id);

        $this->assertDatabaseHas('owner_users', [
            'owner_id' => $this->owner->id,
            'user_id'  => $user->id,
            'role'     => 'manager',
        ]);

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_accept_attaches_existing_user_to_pivot_only(): void
    {
        Notification::fake();

        $existing = User::factory()->create([
            'email'    => 'preexisting@example.com',
            'password' => bcrypt('original-pw'),
        ]);

        $invitation = $this->service->invite(
            'preexisting@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'read_only', null, $this->inviter,
        );

        $user = $this->service->accept($invitation->token, '', '');

        $this->assertSame($existing->id, $user->id);
        $this->assertDatabaseHas('owner_users', [
            'owner_id' => $this->owner->id,
            'user_id'  => $existing->id,
            'role'     => 'read_only',
        ]);

        // Password gốc không đổi
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('original-pw', $user->fresh()->password));
    }

    public function test_accept_rejects_expired_token(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'late@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'manager', null, $this->inviter,
        );
        $invitation->update(['expires_at' => now()->subHour()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hết hạn');
        $this->service->accept($invitation->token, 'X', 'password123');
    }

    public function test_accept_rejects_already_accepted_token(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'twice@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'manager', null, $this->inviter,
        );
        $invitation->update(['accepted_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('đã được sử dụng');
        $this->service->accept($invitation->token, 'X', 'password123');
    }

    public function test_invite_rejects_invalid_role_for_tenant_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->invite(
            'wrong@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'admin', null, $this->inviter,
        );
    }

    public function test_show_route_renders_accept_page(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'visitor@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'read_only', null, $this->inviter,
        );

        $response = $this->get(route('invitations.accept', $invitation->token));
        $response->assertOk();
        $response->assertSee($this->owner->name);
        $response->assertSee('Read only');
    }

    public function test_show_route_returns_410_for_expired(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'late2@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'read_only', null, $this->inviter,
        );
        $invitation->update(['expires_at' => now()->subDay()]);

        $response = $this->get(route('invitations.accept', $invitation->token));
        $response->assertStatus(410);
        $response->assertSee('hết hạn');
    }

    public function test_store_route_creates_user_and_logs_in(): void
    {
        Notification::fake();

        $invitation = $this->service->invite(
            'flow@example.com', UserInvitation::TENANT_OWNER, $this->owner->id, 'manager', null, $this->inviter,
        );

        $response = $this->post(route('invitations.accept.store', $invitation->token), [
            'name'                  => 'Flow User',
            'password'              => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
        ]);

        $response->assertRedirect('/publisher');
        $this->assertAuthenticated();

        $user = User::where('email', 'flow@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('publisher'));
    }
}
