<?php

namespace Tests\Feature\Api;

use App\Models\{Owner, OwnerUser, Screen, Site, User};
use Laravel\Sanctum\Sanctum;

// Independent review checks: assertions describe the required secure behavior.
class T1ReviewTest extends ApiAuthorizationTest
{
    private function fixture(string $role = 'owner', string $status = 'active'): array
    {
        $owner = Owner::factory()->create(['status' => $status]);
        $site = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => $role]);
        Sanctum::actingAs($user, ['manage']);
        return [$owner, $site, $screen, $user];
    }

    public function test_review_revoked_member_cannot_read_screen(): void
    {
        [$owner, $site, $screen, $user] = $this->fixture();
        OwnerUser::where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/screens/'.$screen->id)->assertForbidden();
    }

    public function test_review_revoked_member_cannot_write_screen(): void
    {
        [$owner, $site, $screen, $user] = $this->fixture();
        OwnerUser::where('user_id', $user->id)->delete();
        $this->putJson('/api/v1/screens/'.$screen->id, ['name' => 'revoked'])->assertForbidden();
    }

    public function test_review_suspended_owner_cannot_write(): void
    {
        [$owner, $site, $screen] = $this->fixture('owner', 'suspended');
        $this->putJson('/api/v1/screens/'.$screen->id, ['name' => 'suspended edit'])->assertForbidden();
    }

    public function test_review_scheduler_cannot_set_floor_price(): void
    {
        [$owner, $site, $screen] = $this->fixture('scheduler');
        $this->putJson('/api/v1/screens/'.$screen->id, ['inventory' => ['floor_cpm' => 123]])->assertForbidden();
    }

    public function test_review_read_only_cannot_read_revenue(): void
    {
        [$owner] = $this->fixture('read_only');
        $this->getJson('/api/v1/owners/'.$owner->id.'/stats')->assertForbidden();
    }

    public function test_review_reporting_only_cannot_read_inventory(): void
    {
        $this->fixture('reporting_only');
        $this->getJson('/api/v1/screens')->assertForbidden();
    }

    public function test_review_screen_response_hides_device_token(): void
    {
        [$owner, $site, $screen] = $this->fixture('read_only');
        $screen->update(['device_token' => 'review-fixture-not-a-real-secret']);
        $this->getJson('/api/v1/screens/'.$screen->id)->assertOk()->assertJsonMissingPath('device_token');
    }
}
