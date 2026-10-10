@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Thanh toán thành công | OOHX')

@section('content')
{{--
    Trang xác nhận đã gửi yêu cầu thanh toán — đọc `/api/v2` từ trình duyệt.

    ══ Chuyển sang API sửa một lỗi thật ══

    Bản cũ viết CỨNG thẻ trạng thái:

        <span class="badge b-org">Chờ xác nhận</span>

    …bất kể `$payment->status`. Một lần trả đã được xác nhận vẫn hiện "Chờ xác
    nhận" trên trang này. Nay chữ lấy từ `status_label` của DTO, tức từ cùng
    một bảng chữ với mọi chỗ khác (`Payment::STATUS_LABELS`).

    ══ Không cần endpoint mới ══

    `GET /api/v2/campaigns/{campaign}/payments` đã trả `payments` theo thứ tự
    `latest()`, nên `payments[0]` đúng là thứ bản cũ lấy bằng
    `$campaign->payments()->latest()->first()`.
--}}
@php
    // Dựng ở đây, không ở controller: `TruongTrangDocTest` trích đường API bằng
    // cách tìm `url('/api/v2…')` trong tệp Blade.
    $cauHinh = [
        'api'        => url('/api/v2/campaigns/' . $campaign->id . '/payments'),
        'urlDangNhap' => url('/login'),
    ];
@endphp

<div class="w" style="padding-top:48px;padding-bottom:64px">
    <div style="max-width:520px;margin:0 auto;text-align:center">
        <script type="application/json" data-ok-cauhinh>@json($cauHinh, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

        <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:64px;height:64px;margin-bottom:16px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>

        <h1 style="font-size:24px;font-weight:800;color:var(--t1);margin-bottom:8px">Yêu cầu thanh toán đã gửi!</h1>
        <p style="font-size:15px;color:var(--t3);line-height:1.6;margin-bottom:24px">
            Chúng tôi đã nhận được thông báo chuyển khoản của bạn. Thanh toán sẽ được xác nhận trong 1-2 ngày làm việc.
        </p>

        <div class="auth-error" style="margin-bottom:16px" data-ok-loi hidden></div>

        {{-- Khung chờ: cùng hình dạng thẻ thật, nên không có nhảy khung khi dữ liệu về --}}
        <div data-ok-cho aria-hidden="true" style="background:var(--bg2);border-radius:14px;padding:20px;margin-bottom:24px;text-align:left">
            @for($i = 0; $i < 4; $i++)
            <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--ln2)">
                <span style="color:var(--t4)">&nbsp;</span><span>&nbsp;</span>
            </div>
            @endfor
        </div>

        <div data-ok-the hidden style="background:var(--bg2);border-radius:14px;padding:20px;margin-bottom:24px;text-align:left">
            <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--ln2)">
                <span style="color:var(--t4)">Mã giao dịch</span>
                <span style="font-weight:700;color:var(--t1)" data-ok-ma></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--ln2)">
                <span style="color:var(--t4)">Số hoá đơn</span>
                <span style="font-weight:700;color:var(--t1)" data-ok-hoadon></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--ln2)">
                <span style="color:var(--t4)">Số tiền</span>
                <span style="font-weight:700;color:var(--t1)" data-ok-tien></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0">
                <span style="color:var(--t4)">Trạng thái</span>
                <span class="badge" data-ok-trangthai></span>
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a href="{{ route('buyer.campaigns.show', $campaign) }}" class="btn btn-p btn-sm">Xem Campaign</a>
            <a href="{{ route('buyer.dashboard') }}" class="btn btn-s btn-sm">Bảng điều khiển</a>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var d  = document;
    var cf = JSON.parse(d.querySelector('[data-ok-cauhinh]').textContent);

    var oCho  = d.querySelector('[data-ok-cho]');
    var oThe  = d.querySelector('[data-ok-the]');
    var oLoi  = d.querySelector('[data-ok-loi]');

    var oMa   = d.querySelector('[data-ok-ma]');
    var oHD   = d.querySelector('[data-ok-hoadon]');
    var oTien = d.querySelector('[data-ok-tien]');
    var oTT   = d.querySelector('[data-ok-trangthai]');

    // Trạng thái ban đầu do SCRIPT đặt, không để markup giữ một mình.
    //
    // Hai lý do. Một: `hidden` trong markup và `hidden` trong script là hai
    // nguồn sự thật cho cùng một thứ, và chúng trôi khỏi nhau được. Hai: bộ
    // khung jsdom dựng mỗi móc thành một thẻ TRỐNG, không mang thuộc tính nào
    // từ Blade — nên thứ chỉ có trong markup là thứ không ca test nào chạm
    // được. Ô lỗi là chỗ đáng canh nhất: hiện sẵn một khối lỗi rỗng ngay khi
    // mở trang là lỗi người dùng thấy đầu tiên.
    oThe.hidden = true;
    oCho.hidden = false;
    oLoi.hidden = true;

    /** VND nguyên, dấu chấm phân nhóm — cùng cách `number_format(…, 0, ',', '.')`. */
    function tien(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ₫';
    }

    /**
     * Màu theo trạng thái — **client sở hữu màu, máy chủ sở hữu chữ**.
     *
     * Nhánh mặc định là `b-gray`, và nó phải có trong `frontpage.css`: mười chỗ
     * từng trỏ vào một class chưa ai định nghĩa, nên thẻ ra DOM không nền
     * không màu chữ. `class-css.test.mjs` canh đúng việc đó.
     */
    function mau(ma) {
        // Cùng bảng màu với trang thanh toán, cùng MÃ của `Payment::STATUS_LABELS`.
        //
        // Bản đầu của hàm này viết `succeeded` — một mã không tồn tại; mã đúng
        // là `completed` — và cho `processing` một class màu xanh dương **chưa
        // ai định nghĩa** trong `frontpage.css`. Thẻ sẽ ra DOM không nền, không
        // màu chữ: đúng lỗi `.b-gray` từng gây ra ở mười chỗ.
        //
        // Không viết tên class chết đó ra ở đây, kể cả trong chú thích:
        // `class-css.test.mjs` quét MỌI chuỗi `b-*` trong mọi tệp Blade và
        // không đọc chú thích — đó là lý do nó không cần đặc cách nào. Một
        // chú thích nhắc tên class sai sẽ làm nó đỏ, và đặc cách để lọt chú
        // thích là mở đúng cái cửa cho một lỗi thật.
        var m = { pending: 'b-org', processing: 'b-org', completed: 'b-grn', failed: 'b-red', refunded: 'b-gray' };

        return m[ma] || 'b-gray';
    }

    function doiDangNhap() {
        oCho.hidden = true;
        oLoi.hidden = false;
        oLoi.textContent = 'Phiên đăng nhập đã hết. ';
        var a = d.createElement('a');
        a.href = cf.urlDangNhap;
        a.textContent = 'Đăng nhập lại';
        oLoi.appendChild(a);
    }

    fetch(cf.api, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) {
            if (r.status === 401) { doiDangNhap(); return Promise.reject({ daXuLy: true }); }
            return r.json().then(function (j) { return r.ok ? j : Promise.reject(j || {}); });
        })
        .then(function (j) {
            var ds = (j.data && j.data.payments) || [];

            // Không có lần trả nào: bản cũ ẩn cả thẻ bằng một directive `if`
            // của Blade, nên giữ đúng hành vi đó thay vì hiện một thẻ rỗng.
            //
            // KHÔNG viết tên directive đó kèm dấu a-còng ở đây, kể cả trong một
            // chú thích JS: Blade biên dịch TRƯỚC khi có JS, nên nó đọc chuỗi
            // đó như một directive thật và tệp vỡ với "unexpected end of file,
            // expecting elseif/else/endif". Đã gặp.
            if (! ds.length) { oCho.hidden = true; return; }

            var p = ds[0];

            oMa.textContent   = p.transaction_ref || '—';
            oHD.textContent   = p.invoice_number || '—';
            oTien.textContent = tien(p.amount);

            // Chữ từ `status_label` của máy chủ, KHÔNG viết cứng. Bản cũ in
            // "Chờ xác nhận" cho mọi trạng thái.
            oTT.textContent = p.status_label || p.status || '—';
            oTT.className   = 'badge ' + mau(p.status);

            oCho.hidden = true;
            oThe.hidden = false;
        })
        .catch(function (kq) {
            if (kq && kq.daXuLy) { return; }
            oCho.hidden = true;
            oLoi.textContent = (kq && kq.message) || 'Không tải được thông tin thanh toán.';
            oLoi.hidden = false;
        });
})();
</script>
@endsection
