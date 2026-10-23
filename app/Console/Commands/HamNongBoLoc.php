<?php

namespace App\Console\Commands;

use App\Services\FrontpageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Hâm nóng bộ lọc tổng hợp của `/explore` trước khi người dùng cần nó.
 *
 * ══ Vì sao lệnh này tồn tại ══
 *
 * `getFilterAggregates()` cache 1800 giây. Nghĩa là **cứ 30 phút một người
 * dùng thật trả toàn bộ phép tính** — không phải vì có sự cố, mà vì đó là
 * cách `Cache::remember` hoạt động: người đầu tiên tới sau khi hết hạn là
 * người tính.
 *
 * Đo 23/10/2026 trên 50.000 bản ghi giả: **1.362ms**, 11 truy vấn, 1.024ms
 * trong SQL. Ở 10.000 là 100ms, nên đây là đường **dốc nhất** của `/explore`
 * (7,2× khi dữ liệu gấp 5) và là đường đầu tiên chạm 300ms khi kho lớn lên.
 *
 * Tối ưu từng truy vấn giúp được một phần (lượt này cắt ~310ms), nhưng nó
 * không đổi được điều cốt lõi: **một người dùng nào đó vẫn phải là người
 * tính**. Lệnh này đổi "người đó" từ một người mua thành một tiến trình nền.
 *
 * ══ Tính TRƯỚC, `put` SAU — không `forget` rồi tính ══
 *
 * Cách viết hiển nhiên là `Cache::forget()` rồi gọi `getFilterAggregates()`
 * cho nó tính lại. Sai: giữa hai lời gọi đó có một khe hở hơn một giây với
 * cache rỗng, và người dùng rơi vào đúng khe ấy phải trả cả phép tính — tức
 * lệnh hâm nóng tự tạo ra chính thứ nó sinh ra để tránh, đều đặn theo lịch.
 *
 * Cũng không dùng `Cache::remember`: khoá còn hạn thì nó **không tính gì cả**,
 * nên lệnh chạy xong, in một dòng thành công, và cache vẫn là bản cũ sắp hết
 * hạn. Đó là một lệnh hâm nóng không hâm nóng.
 *
 * Nên nó gọi `tinhBoLoc()` (không qua cache), rồi mới `Cache::put`. Khoá cũ
 * vẫn phục vụ người dùng suốt lúc tính, và bị thay bằng một phép ghi.
 *
 * ══ Lệnh này hỏng thì KHÔNG ai thấy gì ══
 *
 * Không phải cơ chế bảo vệ: `getFilterAggregates()` vẫn tự tính khi cache
 * nguội, y như trước lệnh này. Lệnh chết nghĩa là quay về hành vi cũ — chậm
 * hơn cho một người mỗi 30 phút — chứ không phải bộ lọc trống. Đó là lý do
 * nó không cần cảnh báo gắt, chỉ cần một dòng log khi hỏng.
 */
class HamNongBoLoc extends Command
{
    protected $signature = 'oohx:ham-nong-bo-loc';

    protected $description = 'Tính trước bộ lọc tổng hợp của /explore để không người dùng nào phải trả phép tính';

    public function handle(FrontpageService $kho): int
    {
        $batDau = microtime(true);

        $boLoc = $kho->tinhBoLoc();

        Cache::put(FrontpageService::KHOA_BO_LOC, $boLoc, FrontpageService::HAN_BO_LOC);

        $this->info(sprintf(
            'Hâm nóng xong trong %.0fms · %d tỉnh thành · %d chủ kho · %d mạng lưới',
            (microtime(true) - $batDau) * 1000,
            count($boLoc['cities'] ?? []),
            count($boLoc['owners'] ?? []),
            count($boLoc['networks'] ?? []),
        ));

        return self::SUCCESS;
    }
}
