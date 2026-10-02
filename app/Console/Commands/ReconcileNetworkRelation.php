<?php

namespace App\Console\Commands;

use App\Services\Network\NetworkRelationReconciler;
use Illuminate\Console\Command;

/**
 * Đối chiếu quan hệ Màn hình ↔ Mạng lưới về `sites.network_id`.
 *
 * Chạy `--dry-run` trước để xem sẽ đụng bao nhiêu dòng và còn bao nhiêu chỗ
 * mâu thuẫn — đây là phép sửa trên dữ liệu thật, nhìn trước rồi hãy chạy.
 */
class ReconcileNetworkRelation extends Command
{
    protected $signature = 'networks:reconcile {--dry-run : Chỉ xem, không ghi}';

    protected $description = 'Điền sites.network_id từ dữ liệu mạng lưới cũ và báo cáo chỗ mâu thuẫn';

    public function handle(NetworkRelationReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $reconciler->run($dryRun);

        $this->info($dryRun ? '── Chạy thử, không ghi gì ──' : '── Đã ghi ──');

        $this->table(['Chỉ số', 'Giá trị'], [
            ['Tổng số địa điểm',          $result['sites_total']],
            ['Có mạng lưới (trước)',      $result['sites_with_network_before']],
            ['Có mạng lưới (sau)',        $result['sites_with_network_after']],
            ['Điền từ screen_inventory',  $result['filled_from_inventory']],
            ['Điền từ screens.network_code', $result['filled_from_screen_code']],
            ['Địa điểm còn mâu thuẫn',    count($result['conflicts'])],
        ]);

        if ($result['conflicts'] !== []) {
            $this->warn('Những địa điểm dưới đây có mạng lưới khác với dữ liệu trong kho màn hình.');
            $this->warn('KHÔNG tự sửa — cần người xem và quyết định:');

            $this->table(
                ['Địa điểm', 'Mạng lưới của địa điểm', 'Mạng lưới trong kho'],
                array_map(
                    fn ($c) => [$c['site_id'], $c['site_network_id'], $c['inventory_network_id']],
                    array_slice($result['conflicts'], 0, 30),
                ),
            );

            if (count($result['conflicts']) > 30) {
                $this->warn('... và ' . (count($result['conflicts']) - 30) . ' địa điểm nữa.');
            }
        }

        return self::SUCCESS;
    }
}
