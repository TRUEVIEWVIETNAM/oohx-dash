<?php

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
    | ══ Hai lần đổi trong một ngày, và vì sao ══
    |
    | **Sáng 18/10/2026 — bỏ `tile.openstreetmap.org`.** Mọi yêu cầu tới
    | `{a,b,c}.tile.openstreetmap.org` trả HTTP 503 từ mạng Việt Nam (đo bằng
    | Chrome trên production, 20/20 tile), nên mười hai bản đồ hiện nền xám
    | trơn. Ngoài ra tile server của OSM là hạ tầng **cộng đồng** và chính sách
    | của họ nói rõ nó không dành cho ứng dụng thương mại có lưu lượng.
    |
    | **Trưa 18/10/2026 — bỏ Carto.** Thay OSM bằng Carto là một lỗi, và nó
    | lên production trước khi ai nhìn thấy trang. `basemaps.cartocdn.com` nay
    | **bắt buộc API key**: nó không trả 403, nó trả **HTTP 200** kèm một ảnh
    | PNG 2.049 byte in chữ "API KEY REQUIRED" chéo mặt tile. Phép đo lúc
    | chọn nguồn chỉ nhìn mã trạng thái và thời gian phản hồi, nên Carto trông
    | như nguồn nhanh nhất trong bốn nguồn thử.
    |
    | Hai hệ quả, cả hai đều đáng nhớ:
    |
    |  1. Bản đồ **không hỏng** theo nghĩa kỹ thuật — pin đúng, cụm đúng, nút
    |     đúng — nên không log nào đỏ. Chỉ con mắt mới thấy.
    |  2. Cơ chế dự phòng viết cùng lúc đó **không chạy**: nó nghe sự kiện
    |     `tileerror` của Leaflet, mà một phản hồi 200 kèm ảnh hợp lệ thì
    |     không phải lỗi. Dự phòng chống được nguồn chết, không chống được
    |     nguồn trả rác.
    |
    | Đó là lý do có `php artisan map:kiem-tile`: nó tải một tile đã biết và
    | so **kích thước**, nên nó bắt được đúng kiểu hỏng mà hai lớp trên bỏ sót.
    |
    | ══ Vì sao Esri ══
    |
    | Đo lại từ mạng Việt Nam trưa 18/10/2026, tile trung tâm Hà Nội z13:
    | Esri trả 27.862 byte ảnh đường phố **nhãn tiếng Việt** ("Hồ Tây",
    | "Phường Tứ Liên", "Quận Long Biên"); Carto trả 2.049 byte watermark;
    | OSM không nối được; Wikimedia trả 403 (họ chặn dùng ngoài Wikimedia).
    | Esri còn trả ảnh thật tới z19 (4.593 byte), đủ cho mức phóng to nhất
    | mà các bản đồ của dự án dùng.
    |
    | Esri không dùng `{s}`, nên `tile_subdomains` dưới đây chỉ có tác dụng
    | nếu ai đổi sang một nguồn có chỗ thay đó.
    |
    | **Ghi công là bắt buộc.** Xem `tile_attribution`.
    |
    */

    'tile_url' => env(
        'MAP_TILE_URL',
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
    ),

    'tile_subdomains' => env('MAP_TILE_SUBDOMAINS', 'abc'),

    'tile_attribution' => env(
        'MAP_TILE_ATTRIBUTION',
        '© <a href="https://www.esri.com">Esri</a> · nguồn: Esri, HERE, Garmin, © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
    ),

    /*
    | Nguồn dự phòng, dùng khi nguồn chính trả **lỗi**.
    |
    | Đọc kỹ chữ "lỗi": đây là lớp chống nguồn chết (503, timeout, DNS), không
    | phải lớp chống nguồn trả rác — chuyện Carto 18/10 đi qua lớp này mà
    | không chạm vào nó. Lớp chống rác là `php artisan map:kiem-tile`.
    |
    | Dự phòng là World_Topo_Map: cùng nhà Esri, khác service. Nó đỡ được một
    | service hỏng, không đỡ được cả Esri hỏng. Một nguồn thứ ba thật sự khác
    | nhà cần một tài khoản có API key (MapTiler, Stadia), và đó là quyết định
    | của chủ dự án, không phải một lần sửa.
    |
    | Chỉ bản Next dùng dự phòng; chín view Filament thì không, vì chúng là
    | khu nội bộ và thêm logic vào chín chỗ là thêm chín chỗ có thể sai.
    */
    'tile_fallback_url' => env(
        'MAP_TILE_FALLBACK_URL',
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
    ),

    'tile_fallback_attribution' => env(
        'MAP_TILE_FALLBACK_ATTRIBUTION',
        '© <a href="https://www.esri.com">Esri</a> · nguồn: Esri, HERE, Garmin, © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
    ),

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
    | Ngưỡng 8.000 byte nằm giữa hai con số đã đo (27.862 và 2.049) và cách xa
    | cả hai, nên nó không nhạy với việc nhà cung cấp vẽ lại kiểu bản đồ.
    */

    'kiem_tile_mau' => [
        'z' => (int) env('MAP_KIEM_TILE_Z', 13),
        'x' => (int) env('MAP_KIEM_TILE_X', 6504),
        'y' => (int) env('MAP_KIEM_TILE_Y', 3605),
    ],

    'kiem_tile_toi_thieu' => (int) env('MAP_KIEM_TILE_TOI_THIEU', 8000),

];
