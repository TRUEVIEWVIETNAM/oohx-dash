<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đưa quan hệ Màn hình ↔ Mạng lưới về một nguồn sự thật: `sites.network_id`.
 *
 * Tách khỏi migration để **chạy thử được trước khi chạy thật** và để có test.
 * Một phép sửa dữ liệu production mà không ai chạy thử được là một phép sửa
 * không ai kiểm được.
 *
 * Nguyên tắc: **chỉ điền vào ô trống, không bao giờ ghi đè.** Chỗ nào hai
 * nguồn mâu thuẫn thì báo cáo và để nguyên — sửa dữ liệu lịch sử theo phỏng
 * đoán còn tệ hơn để một chỗ lệch nhìn thấy được.
 */
class NetworkRelationReconciler
{
    /**
     * @return array{
     *     sites_total: int,
     *     sites_with_network_before: int,
     *     sites_with_network_after: int,
     *     filled_from_inventory: int,
     *     filled_from_screen_code: int,
     *     conflicts: array<int, array{site_id: string, site_network_id: int, inventory_network_id: int}>
     * }
     */
    public function run(bool $dryRun = false): array
    {
        $before = $this->sitesWithNetwork();

        // Lập MỘT tập ứng viên cho cả hai chế độ, rồi mới quyết định ghi hay không.
        //
        // Bản trước tính hai nguồn theo hai cách khác nhau tùy chế độ, nên
        // dry-run và chạy thật có thể báo khác nhau — mà dry-run tồn tại chính
        // để cho biết chạy thật sẽ làm gì (Codex R19).
        $plan = $this->plan();

        if (! $dryRun) {
            $this->apply($plan['from_inventory']);
            $this->apply($plan['from_screen_code']);
        }

        return [
            'sites_total'               => DB::table('sites')->count(),
            'sites_with_network_before' => $before,
            'sites_with_network_after'  => $dryRun
                ? $before + count($plan['from_inventory']) + count($plan['from_screen_code'])
                : $this->sitesWithNetwork(),
            'filled_from_inventory'     => count($plan['from_inventory']),
            'filled_from_screen_code'   => count($plan['from_screen_code']),
            'conflicts'                 => array_merge($this->conflicts(), $plan['unresolved']),
        ];
    }

    /**
     * Kế hoạch điền, giống hệt nhau ở cả hai chế độ.
     *
     * Quy tắc bổ sung so với bản trước: một site có dữ liệu MÂU THUẪN ở nguồn
     * nào thì **bị loại khỏi mọi nguồn**, không rơi xuống nguồn sau.
     *
     * Bản trước bỏ qua site có hai màn hình chỉ về hai mạng lưới khác nhau ở
     * lượt kho, rồi lượt hai lại điền nó từ `network_code` — tức vẫn tự chọn
     * khi dữ liệu mâu thuẫn, chỉ là chọn theo nguồn thứ ba. Hai nguồn nói khác
     * nhau cũng vậy.
     *
     * @return array{
     *     from_inventory: array<int, array{site_id: string, network_id: int}>,
     *     from_screen_code: array<int, array{site_id: string, network_id: int}>,
     *     unresolved: array<int, array{site_id: string, site_network_id: int, inventory_network_id: int}>
     * }
     */
    private function plan(): array
    {
        $inventory = $this->candidatesFromInventory();
        $byCode    = $this->candidatesFromScreenCode();
        $ambiguous = $this->ambiguousSites();

        $inventoryBySite = array_column($inventory, 'network_id', 'site_id');

        $unresolved = [];
        foreach ($ambiguous as $siteId) {
            $unresolved[] = [
                'site_id'              => $siteId,
                'site_network_id'      => 0,   // site chưa có mạng lưới
                'inventory_network_id' => 0,   // nhiều giá trị, không quy về một
            ];
        }

        $fromInventory = array_values(array_filter(
            $inventory,
            fn ($row) => ! in_array($row['site_id'], $ambiguous, true),
        ));

        $fromCode = [];
        foreach ($byCode as $row) {
            if (in_array($row['site_id'], $ambiguous, true)) {
                continue;
            }

            // Site đã được nguồn thứ nhất điền: bỏ qua, và nếu hai nguồn nói
            // khác nhau thì coi là chưa giải quyết được.
            if (array_key_exists($row['site_id'], $inventoryBySite)) {
                if ($inventoryBySite[$row['site_id']] !== $row['network_id']) {
                    $unresolved[] = [
                        'site_id'              => $row['site_id'],
                        'site_network_id'      => $row['network_id'],
                        'inventory_network_id' => $inventoryBySite[$row['site_id']],
                    ];
                }

                continue;
            }

            $fromCode[] = $row;
        }

        // Site có hai nguồn nói khác nhau thì không điền từ nguồn nào cả.
        $conflictedSiteIds = array_column($unresolved, 'site_id');
        $fromInventory = array_values(array_filter(
            $fromInventory,
            fn ($row) => ! in_array($row['site_id'], $conflictedSiteIds, true),
        ));

        return [
            'from_inventory'   => $fromInventory,
            'from_screen_code' => $fromCode,
            'unresolved'       => $unresolved,
        ];
    }

    /**
     * Site chưa có mạng lưới nhưng các màn hình của nó chỉ về NHIỀU mạng lưới.
     *
     * @return array<int, string>
     */
    private function ambiguousSites(): array
    {
        if (! Schema::hasTable('screen_inventory')) {
            return [];
        }

        return DB::table('sites')
            ->join('screens', 'screens.site_id', '=', 'sites.id')
            ->join('screen_inventory', 'screen_inventory.screen_id', '=', 'screens.id')
            ->whereNull('sites.network_id')
            ->whereNotNull('screen_inventory.network_id')
            ->groupBy('sites.id')
            ->havingRaw('COUNT(DISTINCT screen_inventory.network_id) > 1')
            ->pluck('sites.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function sitesWithNetwork(): int
    {
        return DB::table('sites')->whereNotNull('network_id')->count();
    }

    /**
     * Site chưa có mạng lưới, mà **mọi** màn hình của nó cùng chỉ về một mạng
     * lưới trong `screen_inventory`.
     *
     * Điều kiện "cùng một" là quan trọng: site có hai màn hình chỉ về hai mạng
     * lưới khác nhau là dữ liệu mâu thuẫn, chọn bừa một cái là bịa.
     *
     * @return array<int, array{site_id: string, network_id: int}>
     */
    private function candidatesFromInventory(): array
    {
        if (! Schema::hasTable('screen_inventory')) {
            return [];
        }

        return DB::table('sites')
            ->join('screens', 'screens.site_id', '=', 'sites.id')
            ->join('screen_inventory', 'screen_inventory.screen_id', '=', 'screens.id')
            ->whereNull('sites.network_id')
            ->whereNotNull('screen_inventory.network_id')
            ->groupBy('sites.id')
            ->havingRaw('COUNT(DISTINCT screen_inventory.network_id) = 1')
            ->selectRaw('sites.id as site_id, MIN(screen_inventory.network_id) as network_id')
            ->get()
            ->map(fn ($r) => ['site_id' => (string) $r->site_id, 'network_id' => (int) $r->network_id])
            ->all();
    }

    /**
     * Lượt hai: điền từ `screens.network_code` cho site vẫn còn trống.
     *
     * @return array<int, array{site_id: string, network_id: int}>
     */
    private function candidatesFromScreenCode(): array
    {
        if (! Schema::hasColumn('screens', 'network_code')) {
            return [];
        }

        return DB::table('sites')
            ->join('screens', 'screens.site_id', '=', 'sites.id')
            ->join('networks', 'networks.code', '=', 'screens.network_code')
            ->whereNull('sites.network_id')
            ->whereNotNull('screens.network_code')
            ->groupBy('sites.id')
            ->havingRaw('COUNT(DISTINCT networks.id) = 1')
            ->selectRaw('sites.id as site_id, MIN(networks.id) as network_id')
            ->get()
            ->map(fn ($r) => ['site_id' => (string) $r->site_id, 'network_id' => (int) $r->network_id])
            ->all();
    }

    /** @param array<int, array{site_id: string, network_id: int}> $rows */
    private function apply(array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('sites')
                ->where('id', $row['site_id'])
                // Điều kiện null lặp lại ở đây chứ không chỉ ở truy vấn chọn:
                // giữa lúc chọn và lúc ghi có thể có người khác đã gán.
                ->whereNull('network_id')
                ->update(['network_id' => $row['network_id']]);
        }
    }

    /**
     * Site đã có mạng lưới nhưng kho màn hình nói khác. Để nguyên, chỉ báo.
     *
     * @return array<int, array{site_id: string, site_network_id: int, inventory_network_id: int}>
     */
    public function conflicts(): array
    {
        if (! Schema::hasTable('screen_inventory')) {
            return [];
        }

        return DB::table('sites')
            ->join('screens', 'screens.site_id', '=', 'sites.id')
            ->join('screen_inventory', 'screen_inventory.screen_id', '=', 'screens.id')
            ->whereNotNull('sites.network_id')
            ->whereNotNull('screen_inventory.network_id')
            ->whereColumn('sites.network_id', '!=', 'screen_inventory.network_id')
            ->selectRaw('DISTINCT sites.id as site_id, sites.network_id as site_network_id, screen_inventory.network_id as inventory_network_id')
            ->get()
            ->map(fn ($r) => [
                'site_id'              => (string) $r->site_id,
                'site_network_id'      => (int) $r->site_network_id,
                'inventory_network_id' => (int) $r->inventory_network_id,
            ])
            ->all();
    }
}
