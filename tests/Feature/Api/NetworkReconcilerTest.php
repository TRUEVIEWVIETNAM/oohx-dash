<?php

namespace Tests\Feature\Api;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Services\Network\NetworkRelationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phép đối chiếu dữ liệu của giai đoạn 3.
 *
 * Đây là phép sửa chạy trên dữ liệu production, nên nó phải chứng minh được
 * ba điều trước khi ai đó bấm chạy: **chỉ điền vào ô trống**, **không đoán khi
 * mâu thuẫn**, và **chạy lại không đổi kết quả**.
 */
class NetworkReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = Owner::factory()->create(['status' => 'active']);
    }

    private function network(string $code): Network
    {
        return Network::factory()->create(['code' => $code, 'name' => $code, 'owner_id' => $this->owner->id]);
    }

    private function site(?int $networkId = null): Site
    {
        return Site::factory()->create(['owner_id' => $this->owner->id, 'network_id' => $networkId]);
    }

    private function screenAt(Site $site, ?int $inventoryNetworkId = null, ?string $networkCode = null): Screen
    {
        $screen = Screen::factory()->create([
            'owner_id'     => $this->owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'network_code' => $networkCode,
        ]);

        ScreenInventory::factory()->create([
            'screen_id'  => $screen->id,
            'network_id' => $inventoryNetworkId,
        ]);

        return $screen;
    }

    public function test_dien_mang_luoi_cho_dia_diem_con_trong_tu_kho_man_hinh(): void
    {
        $net  = $this->network('winmart');
        $site = $this->site(null);
        $this->screenAt($site, $net->id);

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertSame(1, $result['filled_from_inventory']);
        $this->assertSame($net->id, (int) DB::table('sites')->where('id', $site->id)->value('network_id'));
    }

    public function test_dien_tu_network_code_khi_kho_khong_co(): void
    {
        $net  = $this->network('aeon');
        $site = $this->site(null);
        $this->screenAt($site, null, 'aeon');

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertSame(1, $result['filled_from_screen_code']);
        $this->assertSame($net->id, (int) DB::table('sites')->where('id', $site->id)->value('network_id'));
    }

    public function test_khong_ghi_de_mang_luoi_da_co(): void
    {
        $keep  = $this->network('giu-nguyen');
        $other = $this->network('khac');
        $site  = $this->site($keep->id);
        $this->screenAt($site, $other->id);

        app(NetworkRelationReconciler::class)->run();

        $this->assertSame(
            $keep->id,
            (int) DB::table('sites')->where('id', $site->id)->value('network_id'),
            'Dữ liệu đã có không được ghi đè — đó là quyết định của người, không phải của migration.'
        );
    }

    public function test_bao_cao_cho_mau_thuan_thay_vi_tu_sua(): void
    {
        $siteNet = $this->network('theo-dia-diem');
        $invNet  = $this->network('theo-kho');
        $site    = $this->site($siteNet->id);
        $this->screenAt($site, $invNet->id);

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertCount(1, $result['conflicts']);
        $this->assertSame($siteNet->id, $result['conflicts'][0]['site_network_id']);
        $this->assertSame($invNet->id, $result['conflicts'][0]['inventory_network_id']);
    }

    public function test_khong_doan_khi_hai_man_hinh_cung_dia_diem_chi_ve_hai_mang_luoi(): void
    {
        $a    = $this->network('net-a');
        $b    = $this->network('net-b');
        $site = $this->site(null);

        $this->screenAt($site, $a->id);
        $this->screenAt($site, $b->id);

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertSame(0, $result['filled_from_inventory'], 'Hai màn hình chỉ về hai nơi là dữ liệu mâu thuẫn — chọn bừa một cái là bịa.');
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('network_id'));
    }

    public function test_chay_lai_khong_doi_ket_qua(): void
    {
        $net  = $this->network('winmart');
        $site = $this->site(null);
        $this->screenAt($site, $net->id);

        $first  = app(NetworkRelationReconciler::class)->run();
        $second = app(NetworkRelationReconciler::class)->run();

        $this->assertSame(1, $first['filled_from_inventory']);
        $this->assertSame(0, $second['filled_from_inventory'], 'Lần hai không còn ô trống nào để điền.');
        $this->assertSame($first['sites_with_network_after'], $second['sites_with_network_after']);
    }

    public function test_chay_thu_khong_ghi_gi(): void
    {
        $net  = $this->network('winmart');
        $site = $this->site(null);
        $this->screenAt($site, $net->id);

        $result = app(NetworkRelationReconciler::class)->run(dryRun: true);

        $this->assertSame(1, $result['filled_from_inventory'], 'Chạy thử vẫn phải nói sẽ điền bao nhiêu.');
        $this->assertNull(
            DB::table('sites')->where('id', $site->id)->value('network_id'),
            'Nhưng không được ghi gì.'
        );
    }

    public function test_lenh_chay_thu_chay_duoc(): void
    {
        $this->artisan('networks:reconcile', ['--dry-run' => true])->assertSuccessful();
    }
}
