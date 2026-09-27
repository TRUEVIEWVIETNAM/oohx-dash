<?php
namespace Tests\Feature\Buyer;

use App\Models\{Owner, Site, Screen, ScreenInventory, Organization};
use App\Services\{CartService, CampaignService};
use Symfony\Component\HttpKernel\Exception\HttpException;

class T2ReviewTest extends CartPricingTest
{
    private function sample(array $options = []): array
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);
        ScreenInventory::create(['screen_id' => $screen->id, 'pricing_model' => 'io', 'io_rate' => 1000000, 'io_rate_unit' => 'month', 'spot_length' => 15]);
        $svc = app(CartService::class);
        $cart = $svc->getOrCreateCart(auth()->user());
        $item = $svc->addItem($cart, $screen->id, $options + ['start_date' => now()->addDays(7)->toDateString(), 'end_date' => now()->addDays(36)->toDateString()]);
        return [$svc, $cart, $item, $screen];
    }

    public function test_review_notes_only_preserves_purchased_units(): void
    {
        [$svc, $cart, $item] = $this->sample(['duration_units' => 3]);
        $updated = $svc->updateItem($item, ['notes' => 'Keep the same purchase']);
        $this->assertSame(3, (int) $updated->duration_units);
        $this->assertEquals(3000000, (float) $updated->estimated_cost);
    }

    public function test_review_start_only_cannot_reverse_dates(): void
    {
        [$svc, $cart, $item] = $this->sample();
        $this->putJson(route('buyer.cart.update', $item), ['start_date' => now()->addDays(60)->toDateString()])->assertStatus(422);
    }

    public function test_review_end_only_valid_update_is_accepted(): void
    {
        [$svc, $cart, $item] = $this->sample();
        $this->putJson(route('buyer.cart.update', $item), ['end_date' => now()->addDays(66)->toDateString()])->assertOk();
    }

    public function test_review_legacy_cart_requires_requote(): void
    {
        [$svc, $cart, $item, $screen] = $this->sample();
        $item->update(['rate_snapshot' => null, 'rate_captured_at' => null]);
        $screen->inventory()->update(['io_rate' => 2000000]);
        $this->expectException(HttpException::class);
        app(CampaignService::class)->createFromCart(Organization::find(auth()->user()->current_organization_id), auth()->user(), $cart->fresh(), ['name' => 'Legacy review']);
    }

    public function test_review_device_count_is_part_of_rate_snapshot(): void
    {
        [$svc, $cart, $item, $screen] = $this->sample();
        $before = $svc->rateSnapshot($screen->inventory);
        $screen->inventory()->update(['screen_count_override' => 5]);
        $after = $svc->rateSnapshot($screen->fresh('inventory')->inventory);
        $this->assertNotSame($before, $after, 'Billable quantity changed, snapshot must invalidate the old quote.');
    }
}
