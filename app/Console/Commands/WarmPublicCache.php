<?php

namespace App\Console\Commands;

use App\Services\FrontpageService;
use App\Services\ProductService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dựng sẵn các số liệu tổng hợp mà trang công khai cần.
 *
 * ## Vấn đề nó giải
 *
 * `deploy.sh` có bước xoá cache. Sau đó **người truy cập đầu tiên** phải dựng
 * lại toàn bộ số liệu tổng hợp trong một request: đếm màn hình theo thành phố,
 * theo mạng lưới, theo loại điểm đặt, khoảng giá… trên gần 3.000 địa điểm.
 *
 * Đây không phải lo xa. Lần deploy 02/10/2026 lúc 06:24, yêu cầu đầu tiên tới
 * `oohx.net` **timeout ở 100s** và Cloudflare trả 524; yêu cầu thứ hai trả
 * 200 trong 1,26s. Nghĩa là mỗi lần deploy, khách đầu tiên gặp trang lỗi.
 *
 * Chạy lệnh này ngay sau bước xoá cache thì request đầu là của chính máy chủ,
 * không phải của khách.
 *
 * ## Vì sao là lệnh artisan chứ không phải mấy dòng `curl` trong `deploy.sh`
 *
 * `deploy.sh` nằm trên VPS, **không** trong repo: không ai review được qua
 * pull request, và sửa nó không để lại dấu vết trong git. Đặt phần logic ở
 * đây thì nó được review, có lịch sử, và `deploy.sh` chỉ cần thêm một dòng.
 *
 * Gọi `curl` vào chính mình cũng dễ hỏng theo cách khó thấy: nó phụ thuộc
 * phân giải tên miền nội bộ, chứng chỉ, và vòng qua Cloudflare.
 *
 * ## Nguyên tắc: hâm cache KHÔNG được làm hỏng deploy
 *
 * Mọi lỗi ở đây đều bị bắt và chỉ báo cáo. Một aggregate hỏng thì hậu quả là
 * trang đầu chậm, không phải deploy đỏ và ứng dụng nằm trong chế độ bảo trì.
 */
class WarmPublicCache extends Command
{
    protected $signature = 'oohx:warm-cache';

    protected $description = 'Dựng sẵn số liệu tổng hợp của trang công khai, chạy sau khi xoá cache';

    public function handle(FrontpageService $frontpage, ProductService $products): int
    {
        $tasks = [
            'Số liệu tổng quan'        => fn () => $frontpage->getHeroStats(),
            'Loại điểm đặt'            => fn () => $frontpage->getVenueTypesWithCounts(),
            'Loại điểm đặt + mạng lưới' => fn () => $frontpage->getCategoriesWithNetworks(),
            'Thành phố nhiều màn hình' => fn () => $frontpage->getTopCities(20),
            'Địa điểm theo vùng'       => fn () => $frontpage->getLocationsByRegion(),
            'Bộ lọc màn hình'          => fn () => $frontpage->getFilterAggregates(),
            'Bộ lọc sản phẩm'          => fn () => $products->getFilterAggregates(),
            'Màn hình nổi bật'         => fn () => $frontpage->getFeaturedScreens(),
            'Owner nổi bật'            => fn () => $frontpage->getFeaturedOwners(),
            'Sản phẩm nổi bật'         => fn () => $products->getFeaturedProducts(),
        ];

        $failed = 0;

        foreach ($tasks as $label => $task) {
            $startedAt = microtime(true);

            try {
                $task();
                $ms = (int) round((microtime(true) - $startedAt) * 1000);
                $this->line(sprintf('  <fg=green>✓</> %-28s %5d ms', $label, $ms));
            } catch (Throwable $e) {
                $failed++;
                // Không ném tiếp: hâm cache hỏng thì trang đầu chậm, còn ném
                // tiếp thì deploy đỏ và ứng dụng kẹt trong chế độ bảo trì.
                $this->line(sprintf('  <fg=yellow>!</> %-28s %s', $label, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $this->warn("{$failed} phần không dựng được — trang đầu sẽ chậm ở những phần đó.");
        }

        // Luôn trả 0: xem docblock. `deploy.sh` dùng `set -e`.
        return self::SUCCESS;
    }
}
