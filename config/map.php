<?php

/*
| Khóa MapTiler. **Không bao giờ nằm trong repo** — nó sống ở `.env` trên máy
| chủ, và `.env.example` chỉ có dòng trống làm mẫu.
|
| Nói thẳng một điều để không ai hiểu nhầm: khóa này **hiện ra trong trình
| duyệt của mọi khách**, vì URL tile do chính trình duyệt gọi. Không có cách
| nào giấu nó, và đó là thiết kế của MapTiler. Thứ bảo vệ hạn mức không phải
| sự bí mật mà là **giới hạn origin** đặt trong MapTiler Cloud: chỉ cho phép
| `oohx.net` và `dash.oohx.net`. Không bật giới hạn đó thì bất kỳ ai chép khóa
| cũng tiêu hạn mức của sàn.
*/
$maptilerKey = (string) env('MAPTILER_KEY', '');

$maptiler = 'https://api.maptiler.com/maps/'
    . env('MAPTILER_STYLE', 'streets-v2')
    . '/{z}/{x}/{y}.png?key={key}';

$esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';

$ghiCongMaptiler = '© <a href="https://www.maptiler.com/copyright/">MapTiler</a> · © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';

$ghiCongEsri = '© <a href="https://www.esri.com">Esri</a> · nguồn: Esri, HERE, Garmin, © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';

return [

    /*
    |--------------------------------------------------------------------------
    | Nguồn ảnh nền bản đồ
    |--------------------------------------------------------------------------
    |
    | MỘT nơi duy nhất cho mười hai bản đồ của dự án: chín view Filament và ba
    | bản đồ của trang công khai (bản Next đọc cùng giá trị này qua
    | `webapp/lib/ban-do.ts` — xem ghi chú ở đó về vì sao hai bản sao).
    |
    | ══ Ba lần đổi nguồn trong một ngày, 18/10/2026 ══
    |
    | **Sáng — bỏ `tile.openstreetmap.org`.** Mọi yêu cầu trả HTTP 503 từ mạng
    | Việt Nam (đo bằng Chrome trên production, 20/20 tile), nên mười hai bản
    | đồ hiện nền xám trơn. Ngoài ra tile server của OSM là hạ tầng **cộng
    | đồng**, chính sách của họ không cho dùng cho ứng dụng thương mại.
    |
    | **Trưa — bỏ Carto.** `basemaps.cartocdn.com` nay bắt buộc API key, nhưng
    | nó không trả 403: nó trả **HTTP 200** kèm một PNG 2.049 byte in chữ
    | "API KEY REQUIRED". Không mã lỗi, không `tileerror`, không dòng log nào.
    | Mười hai bản đồ lên production với chữ đó trên nền và chỉ con mắt mới
    | thấy. Đó là lý do có `php artisan map:kiem-tile`.
    |
    | **Chiều — sang MapTiler**, theo quyết định của chủ dự án: một tài khoản
    | có hạn mức rõ ràng thay cho hai nguồn dùng theo lệ. Esri ở lại làm nguồn
    | **không khóa**, và đó không phải chỗ dự phòng cho vui — xem dưới.
    |
    | ══ Vì sao vẫn giữ Esri, và khi nào nó chạy ══
    |
    | `tile_url` chọn MapTiler **chỉ khi có `MAPTILER_KEY`**. Không có khóa thì
    | nó là Esri. Ba chỗ hưởng điều này:
    |
    |  - Máy lập trình và CI không cần khóa, bản đồ vẫn hiện.
    |  - Khóa gõ sai hay hết hạn ở production thì trang lùi về một nguồn chạy
    |    được, không về nền xám.
    |  - Khóa không phải nằm trong repo để thứ gì đó chạy được.
    |
    | ══ Nhãn tiếng Anh: một đánh đổi đã biết, không phải sơ suất ══
    |
    | Raster tile của MapTiler được render sẵn với nhãn **tiếng Anh** — ở Hà
    | Nội là "West Lake", "Red River", "Tu Lien Bridge". Đã thử cả
    | `?language=vi` lẫn `?lang=vi`: cùng trả đúng 82.078 byte, tức không có
    | tác dụng. Esri thì cho nhãn tiếng Việt ("Hồ Tây", "Phường Tứ Liên").
    |
    | Muốn tiếng Việt trên MapTiler thì phải tạo một **style riêng** trong
    | MapTiler Cloud, đặt ngôn ngữ nhãn là Vietnamese, rồi để tên style đó vào
    | `MAPTILER_STYLE`. Không cần sửa mã, chỉ sửa một biến môi trường.
    |
    */

    'tile_key' => $maptilerKey,

    'tile_url' => env('MAP_TILE_URL') ?: ($maptilerKey !== '' ? $maptiler : $esri),

    'tile_subdomains' => env('MAP_TILE_SUBDOMAINS', 'abc'),

    'tile_attribution' => env('MAP_TILE_ATTRIBUTION')
        ?: ($maptilerKey !== '' ? $ghiCongMaptiler : $ghiCongEsri),

    /*
    | Nguồn dự phòng, dùng khi nguồn chính trả **lỗi**.
    |
    | Đọc kỹ chữ "lỗi": đây là lớp chống nguồn chết (503, timeout, DNS), không
    | phải lớp chống nguồn trả rác — chuyện Carto 18/10 đi qua lớp này mà
    | không chạm vào nó. Lớp chống rác là `php artisan map:kiem-tile`.
    |
    | Dự phòng là Esri: khác nhà với MapTiler và không cần khóa, nên nó đỡ
    | được cả hai kiểu hỏng của nguồn chính — nhà cung cấp sập, hoặc hạn mức
    | 100.000 tile/tháng cạn giữa tháng.
    |
    | Chỉ bản Next dùng dự phòng; chín view Filament thì không, vì chúng là
    | khu nội bộ và thêm logic vào chín chỗ là thêm chín chỗ có thể sai.
    */
    'tile_fallback_url' => env('MAP_TILE_FALLBACK_URL', $esri),

    'tile_fallback_attribution' => env('MAP_TILE_FALLBACK_ATTRIBUTION') ?: $ghiCongEsri,

    'tile_max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

    /*
    |--------------------------------------------------------------------------
    | Tile mẫu để `map:kiem-tile` soát nguồn
    |--------------------------------------------------------------------------
    |
    | Một ô đã biết ở trung tâm Hà Nội. Lệnh tải đúng ô này rồi so kích thước
    | với `kiem_tile_toi_thieu`: nguồn sống trả hàng chục KB, nguồn đòi API key
    | trả khoảng 2 KB watermark, nguồn chết thì không trả gì.
    |
    | Ba con số đã đo cùng một ô, cùng một mạng, ngày 18/10/2026:
    | MapTiler 82.078 byte · Esri 27.862 byte · Carto 2.049 byte watermark.
    | Ngưỡng 8.000 nằm giữa hai nhóm và cách xa cả hai, nên nó không nhạy với
    | việc nhà cung cấp vẽ lại kiểu bản đồ.
    */

    'kiem_tile_mau' => [
        'z' => (int) env('MAP_KIEM_TILE_Z', 13),
        'x' => (int) env('MAP_KIEM_TILE_X', 6504),
        'y' => (int) env('MAP_KIEM_TILE_Y', 3605),
    ],

    'kiem_tile_toi_thieu' => (int) env('MAP_KIEM_TILE_TOI_THIEU', 8000),

];
