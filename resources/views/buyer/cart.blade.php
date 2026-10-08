{{--
    Trang giỏ hàng — đọc dữ liệu qua `GET /api/v2/cart`, không qua model.

    ══ Vì sao trang này không còn nhận `$items` từ controller ══

    Lộ trình (giai đoạn 5, mốc 1) ghi: "Blade hiện tại phải đọc dữ liệu qua
    chính API đó, không gọi thẳng service nữa. Tự mình ăn món mình nấu là cách
    chống lệch hợp đồng tốt nhất — nếu API sai thì trang đang chạy sai ngay,
    phát hiện được liền."

    Mốc 3 làm xong nhóm cần quyền nhưng không có bên nào tiêu thụ nó: bên dự
    kiến là giai đoạn 8, đang hoãn. Nên tầng DTO/phân trang/lỗi của `/api/v2`
    chỉ có test canh, không có lưu lượng thật. Trang này là bên tiêu thụ đầu
    tiên.

    ══ Vì sao gọi từ TRÌNH DUYỆT, không gọi từ PHP ══

    Cách hiển nhiên hơn là PHP tự `Http::get('https://oohx.net/api/v2/cart')`.
    Đừng. OpenLiteSpeed chạy PHP qua một pool worker có hạn; một request đang
    giữ worker mà lại chờ một request khác cũng cần worker thì dưới tải pool
    cạn và site đứng. Tự gọi vào chính mình là cách tạo ra thế đó.

    Cách thứ hai là sub-request nội bộ qua HTTP kernel. Không qua mạng, nhưng
    phải bịa lại phiên và Sanctum trong request con, và `throttle` sẽ đếm đôi
    mỗi lần dựng trang — hạn mức thật của người dùng còn một nửa mà không ai
    nhận ra.

    Gọi từ trình duyệt đi qua NHIỀU stack hơn cả hai cách trên — đúng web
    server, đúng middleware, đúng DTO, đúng bộ dựng lỗi — mà không thêm
    plumbing nào ở máy chủ. Và nó đúng hình dạng giai đoạn 8 sẽ dùng, nên công
    này không phải công bỏ đi.

    ══ Đường GHI vẫn đi route Blade ══

    Nút xoá vẫn POST về `buyer.cart.remove`. Cố ý: khoảng trống cần đóng là
    tầng ĐỌC, và chuyển cả đường ghi trong cùng một lần là nhân đôi diện rủi ro
    trên một trang đụng tiền. `DELETE /api/v2/cart/items/{item}` đã có và đã có
    test; chuyển sang nó là một bước riêng.
--}}
@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Plan của tôi | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    <div class="cart-header">
        <div>
            <h1 class="cart-title">Plan của tôi</h1>
            <p class="cart-sub" data-cart-sub>Đang tải…</p>
        </div>
        <a href="{{ url('/explore') }}" class="btn btn-s btn-sm" data-cart-add hidden>
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;flex-shrink:0"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Thêm màn hình
        </a>
    </div>

    @if(session('success'))
    <div class="cart-alert">{{ session('success') }}</div>
    @endif

    {{-- Trạng thái lỗi. Một trang đọc qua mạng thì phải có chỗ nói ra khi
         mạng hỏng — bản dựng từ model không cần, bản này cần. --}}
    <div class="cart-empty" data-cart-error hidden>
        <div style="font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">Không tải được plan</div>
        <div style="font-size:14px;color:var(--t3);margin-bottom:20px" data-cart-error-msg></div>
        <button type="button" class="btn btn-p btn-sm" data-cart-retry>Thử lại</button>
        {{-- Lối ra thứ hai, cho đúng trường hợp "thử lại" không giải quyết
             được: phiên web còn mà phiên API thì không. --}}
        <a href="{{ url('/login') }}" class="btn btn-s btn-sm" style="margin-left:8px">Đăng nhập lại</a>
    </div>

    <div class="cart-empty" data-cart-empty hidden>
        <svg viewBox="0 0 24 24" fill="var(--t4)" style="width:56px;height:56px;margin-bottom:14px"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96C5 16.1 6.9 18 9 18h12v-2H9.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63H19c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 23.43 5H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
        <div style="font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">Plan trống</div>
        <div style="font-size:14px;color:var(--t3);margin-bottom:20px">Thêm màn hình vào plan từ trang khám phá</div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">Khám phá Inventory</a>
    </div>

    <div class="cart-layout" data-cart-layout hidden>
        <div class="cart-items" data-cart-items></div>

        <div class="cart-sidebar">
            <div class="cart-summary">
                <div class="cart-summary-title">Tóm tắt Plan</div>
                <div class="cart-summary-row">
                    <span>Số màn hình</span>
                    <span style="font-weight:700" data-cart-count></span>
                </div>
                <div class="cart-summary-row">
                    <span>Tổng ước tính</span>
                    <span style="font-weight:700;color:var(--bl)" data-cart-subtotal></span>
                </div>
                <div class="cart-summary-row">
                    {{-- Nhãn phần trăm đọc từ config, KHÔNG phải phép tính tiền.
                         Con số VAT thì lấy từ API (`summary.vat`), vì
                         `PaymentService::withVat()` là chỗ duy nhất được phép
                         tính nó. Trang này từng tự nhân `sum * (1 + vat_rate)`
                         trên tổng dạng float trong khi `withVat` nhận int —
                         hai đường lệch nhau được 1₫, ở đúng con số người mua
                         đọc. --}}
                    <span>VAT ({{ rtrim(rtrim(number_format(config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.') }}%)</span>
                    <span data-cart-vat></span>
                </div>
                <div class="cart-summary-total">
                    <span>Tổng cộng</span>
                    <span data-cart-total></span>
                </div>
                <a href="{{ route('buyer.booking.create') }}" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;margin-top:14px">
                    Tạo Campaign
                </a>
                <div style="text-align:center;font-size:11px;color:var(--t4);margin-top:8px">Bạn sẽ review lại trước khi gửi booking</div>
            </div>
        </div>
    </div>
</div>

{{--
    Mảng dựng trong một khối php rồi mới đưa vào directive json.

    Hai cái bẫy ở chỗ này, cả hai đều đã làm đỏ test một lượt:

    1. KHÔNG viết directive json với một mảng nhiều dòng có hàm bên trong.
       Blade cắt tham số của directive ở dấu `)` ĐẦU TIÊN — tức ngay sau
       `url('/api/v2/cart'` — nên mảng hở, và PHP báo
       "Unclosed '[' ... does not match ')'" kèm một số dòng của file ĐÃ BIÊN
       DỊCH, không phải của file này. Đọc số dòng đó trong file nguồn là đi
       sai đường.

    2. KHÔNG viết tên directive (dấu a-vòng) bên trong một comment Blade.
       `compileStatements` chạy TRƯỚC `compileComments`, nên directive trong
       comment vẫn bị biên dịch thành PHP, rồi chữ quanh nó rơi vào code.
       Comment Blade không che được directive.

    JSON_HEX_TAG để một chuỗi chứa thẻ đóng script không thoát ra khỏi thẻ.
--}}
@php
    $cauHinhGio = [
        'api'     => url('/api/v2/cart'),
        'explore' => url('/explore'),
        'xoaBase' => url('/cart'),
        'csrf'    => csrf_token(),
        'anhThay' => 'https://placehold.co/200x200/F5F5F7/6E6E73?text=No+Photo',
    ];
@endphp
<script type="application/json" data-cart-config>@json($cauHinhGio, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

<script>
(function () {
    'use strict';

    var cf = JSON.parse(document.querySelector('[data-cart-config]').textContent);

    var elSub      = document.querySelector('[data-cart-sub]');
    var elAdd      = document.querySelector('[data-cart-add]');
    var elErr      = document.querySelector('[data-cart-error]');
    var elErrMsg   = document.querySelector('[data-cart-error-msg]');
    var elEmpty    = document.querySelector('[data-cart-empty]');
    var elLayout   = document.querySelector('[data-cart-layout]');
    var elItems    = document.querySelector('[data-cart-items]');
    var elCount    = document.querySelector('[data-cart-count]');
    var elSubtotal = document.querySelector('[data-cart-subtotal]');
    var elVat      = document.querySelector('[data-cart-vat]');
    var elTotal    = document.querySelector('[data-cart-total]');

    var dinhDang = new Intl.NumberFormat('vi-VN');

    function dong(n) {
        return dinhDang.format(n == null ? 0 : n) + ' ₫';
    }

    // `YYYY-MM-DD` → `DD/MM/YYYY` bằng cách cắt chuỗi, KHÔNG qua `new Date()`.
    // `new Date('2026-10-08')` đọc là UTC nửa đêm, nên ở múi giờ âm nó lùi một
    // ngày — và một plan hiện sai ngày bắt đầu là thứ người mua phát hiện
    // trước mình.
    function ngay(s) {
        if (!s) { return '—'; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : s;
    }

    // Escape cho cả thân HTML LẪN giá trị thuộc tính.
    //
    // Cách quen tay — gán `textContent` rồi đọc `innerHTML` — KHÔNG escape dấu
    // nháy kép. Dùng nó trong `src="..."` thì một giá trị chứa `"` thoát ra
    // khỏi thuộc tính. Và tên màn hình ở đây không phải chuỗi do mình gõ: nó
    // vào CSDL qua `ScreenImport` từ file CSV của media owner.
    function chu(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function dongGio(it) {
        var sc  = it.screen || {};
        var loc = (sc.location && sc.location.city) || '';
        var chu_owner = (sc.owner && sc.owner.name) || '';
        var dl  = it.delivery || {};
        var es  = it.estimate || {};

        var chiTiet;
        if (dl.pricing_model === 'cpm') {
            chiTiet = '<div class="cart-item-field">'
                + '<span class="cart-item-label">Kiểu bán</span>'
                + '<span style="color:var(--bl);font-weight:600">CPM</span></div>'
                + '<div class="cart-item-field">'
                + '<span class="cart-item-label">Chi tiết</span>'
                + '<span>' + dinhDang.format(es.unit_price || 0) + ' ₫/CPM × '
                + dinhDang.format(dl.booked_cpms || 0) + ' CPM</span></div>';
        } else {
            var ky = dl.duration_unit === 'week' ? 'tuần' : 'tháng';
            chiTiet = '<div class="cart-item-field">'
                + '<span class="cart-item-label">Kiểu bán</span>'
                + '<span style="color:var(--grn);font-weight:600">I/O Booking</span></div>'
                + '<div class="cart-item-field">'
                + '<span class="cart-item-label">Chi tiết</span>'
                + '<span>' + dinhDang.format(es.unit_price || 0) + ' ₫ × '
                + (dl.screen_count || 0) + ' mh × '
                + (dl.duration_units || 0) + ' ' + ky + '</span></div>';
        }

        var href = sc.slug ? cf.explore + '/' + encodeURIComponent(sc.slug) : cf.explore;
        var per  = it.period || {};

        return '<div class="cart-item">'
            + '<div class="cart-item-img">'
            + '<img src="' + chu(sc.photo_url || cf.anhThay) + '" alt="' + chu(sc.name) + '" loading="lazy">'
            + '</div>'
            + '<div class="cart-item-body">'
            + '<div class="cart-item-top"><div>'
            + '<a href="' + chu(href) + '" class="cart-item-name">' + chu(sc.name) + '</a>'
            + '<div class="cart-item-loc">' + chu(loc) + ' &middot; ' + chu(chu_owner) + '</div>'
            + '</div>'
            + '<form method="POST" action="' + chu(cf.xoaBase + '/' + it.id) + '">'
            + '<input type="hidden" name="_token" value="' + chu(cf.csrf) + '">'
            + '<input type="hidden" name="_method" value="DELETE">'
            + '<button type="submit" class="cart-item-remove" title="Xóa">'
            + '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'
            + '</button></form></div>'
            + '<div class="cart-item-meta">'
            + '<div class="cart-item-field"><span class="cart-item-label">Thời gian</span>'
            + '<span>' + ngay(per.start_date) + ' → ' + ngay(per.end_date) + '</span></div>'
            + chiTiet
            + '<div class="cart-item-field"><span class="cart-item-label">Ước tính</span>'
            + '<span style="font-weight:700;color:var(--t1)">' + dong(es.cost) + '</span></div>'
            + '</div></div></div>';
    }

    function ve(data) {
        var items = (data && data.items) || [];
        var s = (data && data.summary) || {};

        elSub.textContent = items.length + ' màn hình trong plan';

        if (!items.length) {
            elEmpty.hidden = false;
            return;
        }

        elAdd.hidden = false;
        elItems.innerHTML = items.map(dongGio).join('');

        // Cả ba con số lấy thẳng từ API. Không nhân, không cộng lại ở đây —
        // `subtotal + vat === total` là bất biến máy chủ bảo đảm, và tính lại
        // ở client là cách làm nó hết đúng.
        elCount.textContent    = s.item_count == null ? items.length : s.item_count;
        elSubtotal.textContent = dong(s.subtotal);
        elVat.textContent      = dong(s.vat);
        elTotal.textContent    = dong(s.total);

        elLayout.hidden = false;
    }

    function an() {
        elErr.hidden = true;
        elEmpty.hidden = true;
        elLayout.hidden = true;
        elAdd.hidden = true;
    }

    function loi(msg) {
        elSub.textContent = '';
        elErrMsg.textContent = msg;
        elErr.hidden = false;
    }

    function tai() {
        an();
        elSub.textContent = 'Đang tải…';

        fetch(cf.api, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        }).then(function (r) {
            // 401 KHÔNG được xử lý bằng `location.reload()`.
            //
            // Nghe thì hợp lý: phiên hết hạn thì nạp lại, middleware `auth`
            // của route `/cart` sẽ đưa về trang đăng nhập. Nhưng hai lớp xác
            // thực ở đây KHÁC nhau — trang đi qua guard `web`, API đi qua
            // `auth:sanctum` phía sau `EnsureFrontendRequestsAreStateful`, và
            // lớp sau chỉ bật khi `Referer`/`Origin` khớp
            // `config('sanctum.stateful')`.
            //
            // Nên có một thế hoàn toàn khả thi: phiên web còn hợp lệ (trang
            // dựng được) mà API vẫn 401 vì referer bị tước — một tiện ích
            // chặn referer là đủ. Khi ấy nạp lại cho ra đúng kết quả cũ, và
            // người dùng mắc trong một vòng lặp nạp trang không có lối ra.
            //
            // Nói thẳng ra là cách duy nhất đúng: nó phân biệt được "hết phiên"
            // với "trang không gọi được API", và cả hai đều cần người dùng
            // biết chứ không cần trình duyệt quay vòng.
            if (r.status === 401) {
                throw new Error('Phiên đăng nhập không còn hiệu lực cho trang này. Hãy đăng nhập lại.');
            }

            return r.json().catch(function () {
                throw new Error('Máy chủ trả về dữ liệu không đọc được.');
            }).then(function (j) {
                if (!r.ok) {
                    throw new Error((j && j.message) || ('Máy chủ trả lỗi ' + r.status + '.'));
                }
                return j;
            });
        }).then(function (j) {
            if (j) { ve(j.data || {}); }
        }).catch(function (e) {
            loi(e && e.message ? e.message : 'Không nối được tới máy chủ.');
        });
    }

    document.querySelector('[data-cart-retry]').addEventListener('click', tai);
    tai();
})();
</script>
@endsection
