@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Tải nội dung quảng cáo | OOHX')

@section('content')
{{--
    Bước 2 của đặt chỗ — đọc và tải tệp qua `/api/v2`.

    ══ Không có endpoint mới ══

    `GET /api/v2/campaigns/{campaign}` trả `campaign`, `lines` và `creatives`;
    `POST /api/v2/campaigns/{campaign}/creatives` nhận tệp. Cả hai đã có.

    ══ Một trường PHẢI nới ở DTO ══

    Bảng "kích thước màn hình tham khảo" cần độ phân giải **pixel**, mà
    `ScreenSummaryResource.size` chỉ có mét. Nên `BookingLineResource` thêm
    `creative_spec: { width_px, height_px }` — đặt ở đó chứ không ở DTO màn
    hình dùng chung, vì DTO đó còn dùng cho giỏ hàng và danh mục công khai.

    ══ Tiêu đề trang ══

    `@section('title')` cũ nhúng `{{ $campaign->name }}`, mà `$campaign` không
    còn truyền vào view. Tiêu đề nay là tĩnh; tên chiến dịch hiện trong thân
    trang sau khi dữ liệu về. Thẻ `<title>` không phải chỗ đáng gọi thêm một
    truy vấn ở máy chủ.
--}}
@php
    $cauHinh = [
        'api'         => url('/api/v2/campaigns/' . $campaign->id),
        'apiTai'      => url('/api/v2/campaigns/' . $campaign->id . '/creatives'),
        'urlReview'   => route('buyer.booking.review', $campaign),
        'urlDangNhap' => url('/login'),
    ];
@endphp

<div class="w" style="padding-top:24px;padding-bottom:64px">
    <script type="application/json" data-nd-cauhinh>@json($cauHinh, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

    <div class="wz-steps">
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Thông tin</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step on"><div class="wz-step-n">2</div><div class="wz-step-l">Nội dung quảng cáo</div></div>
        <div class="wz-step-line"></div>
        <div class="wz-step"><div class="wz-step-n">3</div><div class="wz-step-l">Xác nhận</div></div>
    </div>

    <h1 class="wz-title">Tải nội dung quảng cáo</h1>
    <p class="wz-sub" data-nd-tieude>&nbsp;</p>

    <div class="cart-alert" data-nd-thanhcong hidden></div>
    <div class="auth-error" style="margin-bottom:16px" data-nd-loi hidden></div>

    <div class="wz-layout">
        <div class="wz-main">
            <div class="wz-card">
                <div class="wz-card-title">Tải lên nội dung</div>
                <form class="wz-upload-form" data-nd-form>
                    <div class="wz-field">
                        <label>Tên nội dung</label>
                        <input type="text" name="name" data-nd-ten placeholder="VD: Banner 16:9 - Honda Civic">
                        <div class="auth-error" data-nd-loi-name hidden></div>
                    </div>
                    <div class="wz-field">
                        <label>Tệp (JPG, PNG, MP4, WebM — tối đa 50MB)</label>
                        <input type="file" name="file" data-nd-tep accept="image/jpeg,image/png,video/mp4,video/webm" required class="wz-file-input">
                        <div class="auth-error" data-nd-loi-file hidden></div>
                    </div>
                    <button type="submit" class="btn btn-p btn-sm" data-nd-tai>
                        <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
                        Tải lên
                    </button>
                </form>
            </div>

            <div class="wz-card" style="margin-top:16px" data-nd-khoi-ds hidden>
                <div class="wz-card-title" data-nd-ds-tieude></div>
                <div class="wz-creative-list" data-nd-ds></div>
            </div>

            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Kích thước màn hình tham khảo</div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--t3)" data-nd-kichthuoc>
                    <div data-nd-kt-cho aria-hidden="true">&nbsp;</div>
                </div>
            </div>
        </div>

        <div class="wz-sidebar">
            <div class="wz-actions-card">
                <div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:12px">Bước tiếp theo</div>
                <p style="font-size:13px;color:var(--t3);margin-bottom:16px">Tải nội dung không bắt buộc. Bạn có thể tải sau khi chiến dịch được duyệt.</p>
                <a href="{{ route('buyer.booking.review', $campaign) }}" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px">
                    Tiếp tục xác nhận <svg viewBox="0 0 24 24" fill="#fff" style="width:16px;height:16px"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var d  = document;
    var cf = JSON.parse(d.querySelector('[data-nd-cauhinh]').textContent);

    var oTieuDe   = d.querySelector('[data-nd-tieude]');
    var oLoi      = d.querySelector('[data-nd-loi]');
    var oOk       = d.querySelector('[data-nd-thanhcong]');
    var oKhoiDs   = d.querySelector('[data-nd-khoi-ds]');
    var oDsTieuDe = d.querySelector('[data-nd-ds-tieude]');
    var oDs       = d.querySelector('[data-nd-ds]');
    var oKT       = d.querySelector('[data-nd-kichthuoc]');
    var oKTCho    = d.querySelector('[data-nd-kt-cho]');

    var fTai   = d.querySelector('[data-nd-form]');
    var oTen   = d.querySelector('[data-nd-ten]');
    var oTep   = d.querySelector('[data-nd-tep]');
    var nutTai = d.querySelector('[data-nd-tai]');

    var LOI = {
        name: d.querySelector('[data-nd-loi-name]'),
        file: d.querySelector('[data-nd-loi-file]')
    };

    // Trạng thái ban đầu do SCRIPT đặt, không để markup giữ một mình: bộ khung
    // jsdom dựng mỗi móc thành một thẻ TRỐNG, không mang thuộc tính nào từ
    // Blade, nên thứ chỉ có trong markup là thứ không ca test nào chạm được.
    oKhoiDs.hidden = true;
    oLoi.hidden    = true;
    oOk.hidden     = true;

    /**
     * Màu theo trạng thái nội dung — **máy chủ sở hữu chữ, client sở hữu màu**.
     *
     * Chữ lấy từ `status_label` của DTO. Bản Blade cũ từng dùng một biểu thức
     * ba ngôi chỉ biết MỘT mã, nên `approved` hiện ra `approved` và `rejected`
     * hiện ra `rejected` — tiếng Anh, trên trang người mua nhìn ngay sau khi
     * tải tệp lên.
     *
     * `rejected` phải KHÁC màu `pending_review`: bản cũ cho cả hai màu xám, tức
     * hai nghĩa khác nhau một màu.
     */
    function mau(ma) {
        var m = { pending_review: 'b-org', approved: 'b-grn', rejected: 'b-red' };

        return m[ma] || 'b-gray';
    }

    function noi(el, chu) {
        if (! el) { return; }
        el.textContent = chu;
        el.hidden = ! chu;
    }

    function xoaLoi() {
        Object.keys(LOI).forEach(function (k) { noi(LOI[k], ''); });
        oLoi.hidden = true;
    }

    function doiDangNhap() {
        oLoi.hidden = false;
        oLoi.textContent = 'Phiên đăng nhập đã hết. ';
        var a = d.createElement('a');
        a.href = cf.urlDangNhap;
        a.textContent = 'Đăng nhập lại';
        oLoi.appendChild(a);
    }

    function goi(url, tuyChon) {
        var o = tuyChon || {};
        o.credentials = 'same-origin';
        o.headers = o.headers || {};
        o.headers['Accept'] = 'application/json';

        return fetch(url, o).then(function (r) {
            if (r.status === 401) { doiDangNhap(); return Promise.reject({ daXuLy: true }); }
            return r.json().then(function (j) { return r.ok ? j : Promise.reject(j || {}); });
        });
    }

    function kb(n) {
        return String(Math.round((Number(n) || 0) / 1024)).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' KB';
    }

    function veDanhSach(ds) {
        oDs.textContent = '';

        if (! ds.length) { oKhoiDs.hidden = true; return; }

        oDsTieuDe.textContent = 'Nội dung đã tải (' + ds.length + ')';

        ds.forEach(function (c) {
            var muc = d.createElement('div');
            muc.className = 'wz-creative-item';

            var anh = d.createElement('div');
            anh.className = 'wz-creative-thumb';

            if (c.type === 'image' && c.download_url) {
                var img = d.createElement('img');
                // `setAttribute`, không nối chuỗi HTML: tên do người dùng đặt.
                img.setAttribute('src', c.download_url);
                img.setAttribute('alt', c.name || '');
                anh.appendChild(img);
            } else {
                var hop = d.createElement('div');
                hop.setAttribute('style', 'display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--bg2)');
                anh.appendChild(hop);
            }

            var tt = d.createElement('div');
            tt.className = 'wz-creative-info';

            var ten = d.createElement('div');
            ten.setAttribute('style', 'font-weight:600;color:var(--t1)');
            ten.textContent = c.name || '';

            var phu = d.createElement('div');
            phu.setAttribute('style', 'font-size:11px;color:var(--t4)');
            // `type_label` của máy chủ, KHÔNG `toUpperCase()`: `vast_tag` hoa
            // lên thành `VAST_TAG`, đúng lỗi đã sửa ở PR #48.
            phu.textContent = (c.type_label || c.type || '') + ' · ' + kb(c.file_size);

            tt.appendChild(ten);
            tt.appendChild(phu);

            var the = d.createElement('span');
            the.className = 'badge ' + mau(c.status);
            the.setAttribute('style', 'font-size:10px');
            the.textContent = c.status_label || c.status || '';

            muc.appendChild(anh);
            muc.appendChild(tt);
            muc.appendChild(the);
            oDs.appendChild(muc);
        });

        oKhoiDs.hidden = false;
    }

    function veKichThuoc(lines) {
        oKT.textContent = '';

        lines.forEach(function (l) {
            var hang = d.createElement('div');
            hang.setAttribute('style', 'display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ln2)');

            var ten = d.createElement('span');
            ten.setAttribute('style', 'font-weight:600;color:var(--t1)');
            ten.textContent = (l.screen && l.screen.name) || '';

            var px = d.createElement('span');
            var s = l.creative_spec || {};
            px.textContent = (s.width_px || '—') + ' x ' + (s.height_px || '—') + ' px';

            hang.appendChild(ten);
            hang.appendChild(px);
            oKT.appendChild(hang);
        });
    }

    function ve(data) {
        var c = data.campaign || {};
        oTieuDe.textContent = (c.name || '') + ' · ' + (c.code || '');

        veDanhSach(data.creatives || []);
        veKichThuoc(data.lines || []);
    }

    function nap() {
        return goi(cf.api).then(function (j) { ve(j.data); });
    }

    fTai.addEventListener('submit', function (e) {
        e.preventDefault();

        if (! oTep.files || ! oTep.files.length) {
            noi(LOI.file, 'Chọn một tệp trước khi tải lên.');
            return;
        }

        xoaLoi();
        oOk.hidden = true;
        nutTai.disabled = true;

        // `FormData`, KHÔNG JSON: đây là đường duy nhất của `/api/v2` nhận tệp.
        // Và không đặt `Content-Type` — trình duyệt phải tự thêm `boundary`.
        var fd = new FormData();
        fd.append('file', oTep.files[0]);
        if (oTen.value) { fd.append('name', oTen.value); }

        goi(cf.apiTai, { method: 'POST', body: fd })
            .then(function () {
                oOk.textContent = 'Đã tải nội dung lên';
                oOk.hidden = false;
                oTen.value = '';
                oTep.value = '';
                return nap();
            })
            .catch(function (kq) {
                if (kq && kq.daXuLy) { return; }

                var conLai = [];

                ((kq && kq.details) || []).forEach(function (ct) {
                    if (LOI[ct.field]) { noi(LOI[ct.field], ct.message); }
                    else { conLai.push(ct.message); }
                });

                if (! ((kq && kq.details) || []).length || conLai.length) {
                    oLoi.textContent = conLai.length ? conLai.join(' ') : ((kq && kq.message) || 'Không tải được tệp lên.');
                    oLoi.hidden = false;
                }
            })
            .then(function () { nutTai.disabled = false; });
    });

    nap().then(function () {
        if (oKTCho && oKTCho.parentNode) { oKTCho.parentNode.removeChild(oKTCho); }
    }).catch(function (kq) {
        if (kq && kq.daXuLy) { return; }
        oLoi.textContent = (kq && kq.message) || 'Không tải được chiến dịch.';
        oLoi.hidden = false;
    });
})();
</script>
@endsection
