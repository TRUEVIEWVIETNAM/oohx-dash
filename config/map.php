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
    | ══ Vì sao đổi khỏi tile.openstreetmap.org (18/10/2026) ══
    |
    | Mọi yêu cầu tới `{a,b,c}.tile.openstreetmap.org` trả **HTTP 503** từ
    | mạng Việt Nam — đo bằng Chrome trên production: 20/20 tile đều 503, nên
    | mọi bản đồ hiện nền xám trơn. Không phải lỗi mã, và nó chạm cả khu quản
    | trị lẫn trang công khai.
    |
    | Tile server của OSM là hạ tầng **cộng đồng**, và chính sách của họ nói rõ
    | nó không dành cho ứng dụng thương mại có lưu lượng. Một sàn TMĐT đang
    | nộp hồ sơ thì không nên phụ thuộc vào đó, kể cả khi nó hoạt động trở lại.
    |
    | ══ Vì sao Carto ══
    |
    | Đo từ cùng mạng ngày 18/10/2026: Carto 200 trong 0,2s (nhanh nhất trong
    | bốn nguồn thử), Esri 200 trong 0,16s, OSM Đức 200 trong 1,6s, OSM chính
    | 503. Carto dựng từ chính dữ liệu OpenStreetMap nên nhãn địa danh tiếng
    | Việt giữ nguyên, và `voyager` là kiểu gần bản đồ đường phố cũ nhất.
    |
    | **Ghi công là bắt buộc** với cả hai nguồn — xem `attribution` dưới. Và
    | nếu lưu lượng lớn lên thì Carto yêu cầu một tài khoản; đó là một quyết
    | định cho sau, không phải hôm nay.
    |
    */

    'tile_url' => env(
        'MAP_TILE_URL',
        'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    ),

    'tile_subdomains' => env('MAP_TILE_SUBDOMAINS', 'abcd'),

    'tile_attribution' => env(
        'MAP_TILE_ATTRIBUTION',
        '© <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> · © <a href="https://carto.com/attributions">CARTO</a>',
    ),

    /*
    | Nguồn dự phòng, dùng khi nguồn chính trả lỗi.
    |
    | Lần hỏng vừa rồi cho thấy một điều: khi tile chết, bản đồ vẫn **trông
    | như đang chạy** — có pin, có nút, chỉ thiếu ảnh nền — nên không ai báo
    | lỗi. Một nguồn thứ hai tự nhảy vào biến một sự cố im lặng thành một sự
    | cố không ai thấy, đúng nghĩa tốt: trang vẫn dùng được.
    |
    | Chỉ bản Next dùng dự phòng; chín view Filament thì không, vì chúng là
    | khu nội bộ và thêm logic vào chín chỗ là thêm chín chỗ có thể sai.
    */
    'tile_fallback_url' => env(
        'MAP_TILE_FALLBACK_URL',
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
    ),

    'tile_fallback_attribution' => env(
        'MAP_TILE_FALLBACK_ATTRIBUTION',
        '© Esri · © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
    ),

    'tile_max_zoom' => (int) env('MAP_TILE_MAX_ZOOM', 19),

];
