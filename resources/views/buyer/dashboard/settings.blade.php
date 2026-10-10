@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Cài đặt | OOHX')

@section('content')
{{--
    Trang cài đặt — đọc VÀ ghi qua /api/v2 từ trình duyệt.

    ══ Ba lý do tôi từng từ chối việc này, và chỗ xử chúng ══

     1. "Ô nhập trống trong một nhịp rồi mới điền."
        → Khung chờ (`[data-td-cho]`) hiện thay cho biểu mẫu, và biểu mẫu chỉ
          hiện sau khi dữ liệu về. Không có nhịp nào ô trống trông như giá trị.

     2. "Trong nhịp đó người dùng gõ được, và lượt điền sau ghi đè."
        → Mọi `input` render với `disabled`, và script bỏ `disabled` SAU khi
          điền. Không gõ được thì không có gì để ghi đè.

     3. "`old()` giữ lại thứ vừa nhập khi validate thất bại."
        → Biểu mẫu gửi qua API, nên không có vòng chuyển hướng nào và `old()`
          không còn vai trò. Lỗi về dưới dạng `details[]` và hiện cạnh từng ô,
          giữ nguyên thứ người dùng đã gõ.

    ══ Trang KHÔNG render sẵn dữ liệu của tổ chức ══

    Không có `{{ $org->tax_id }}` nào trong tệp này, và đó là điều ca test
    `CaiDatToChucTest` đòi: mã số thuế chỉ tới trình duyệt qua một phản hồi API
    đã đi qua `OrganizationPolicy`, không qua HTML của một trang mà server
    render cho bất cứ ai mở được nó.
--}}
<div class="w" style="padding-top:24px;padding-bottom:64px;max-width:640px">

    <h1 class="buyer-welcome-title" style="margin-bottom:24px">Cài đặt</h1>

    @php
        // Cấu hình dựng ở ĐÂY, không ở controller — và không bằng
        // `@json([...])` nhiều dòng. Hai lý do, cả hai đã gặp thật:
        //
        //  1. `@json([…])` trải nhiều dòng làm bộ biên dịch Blade vỡ với
        //     "Unclosed '[' … does not match ')'" — một lỗi 500 lúc render;
        //  2. dựng ở controller thì `TruongTrangDocTest` không trích được đường
        //     API nào, vì nó tìm `url('/api/v2…')` trong tệp Blade. Trang ra
        //     khỏi tầm chốt mà không có gì đỏ.
        $cauHinh = [
            'apiCaiDat'   => url('/api/v2/me/settings'),
            'apiHoSo'     => url('/api/v2/me/profile'),
            'apiMatKhau'  => url('/api/v2/me/password'),
            'apiToChuc'   => url('/api/v2/me/organization'),
            'urlDangNhap' => url('/login'),
        ];
    @endphp

    {{-- `JSON_HEX_TAG` không phải cho đẹp: nó thoát `<` và `>`, nên một giá trị
         chứa `</script>` không đóng sớm khối này. --}}
    <script type="application/json" data-td-cauhinh>@json($cauHinh, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

    <div class="cart-alert" data-td-thanhcong hidden></div>
    <div class="auth-error" style="margin-bottom:16px" data-td-loi hidden></div>

    {{-- Khung chờ: thay cho cả ba thẻ, biến mất khi dữ liệu về --}}
    <div data-td-cho aria-hidden="true">
        @for($i = 0; $i < 3; $i++)
        <div class="wz-card" style="margin-bottom:16px">
            <div class="wz-card-title">&nbsp;</div>
            <div class="wz-field"><label>&nbsp;</label><div class="buyer-stat-l">&nbsp;</div></div>
            <div class="wz-field"><label>&nbsp;</label><div class="buyer-stat-l">&nbsp;</div></div>
        </div>
        @endfor
    </div>

    <div data-td-than hidden>

        {{-- Thông tin cá nhân --}}
        <div class="wz-card" style="margin-bottom:16px">
            <div class="wz-card-title">Thông tin cá nhân</div>
            <form class="wz-form" data-td-form-hoso>
                <div class="wz-field">
                    <label>Họ và tên</label>
                    <input type="text" name="name" data-td-hoso-name required disabled>
                    <div class="auth-error" data-td-loi-name hidden></div>
                </div>
                <div class="wz-field">
                    <label>Email</label>
                    <input type="email" name="email" data-td-hoso-email required disabled>
                    <div class="auth-error" data-td-loi-email hidden></div>
                </div>
                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-p btn-sm" data-td-luu-hoso disabled>Lưu</button>
                </div>
            </form>
        </div>

        {{-- Đổi mật khẩu --}}
        <div class="wz-card" style="margin-bottom:16px">
            <div class="wz-card-title">Đổi mật khẩu</div>
            <form class="wz-form" data-td-form-matkhau>
                <div class="wz-field">
                    <label>Mật khẩu hiện tại</label>
                    <input type="password" name="current_password" data-td-mk-hientai required disabled>
                    <div class="auth-error" data-td-loi-mk-hientai hidden></div>
                </div>
                <div class="wz-field">
                    <label>Mật khẩu mới</label>
                    <input type="password" name="password" data-td-mk-moi required disabled placeholder="Tối thiểu 8 ký tự">
                    <div class="auth-error" data-td-loi-password hidden></div>
                </div>
                <div class="wz-field">
                    <label>Xác nhận mật khẩu mới</label>
                    <input type="password" name="password_confirmation" data-td-mk-xacnhan required disabled>
                </div>
                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-s btn-sm" data-td-luu-matkhau disabled>Đổi mật khẩu</button>
                </div>
            </form>
        </div>

        {{-- Thông tin tổ chức --}}
        <div class="wz-card">
            <div class="wz-card-title">Thông tin tổ chức</div>

            {{-- Hiện khi `can_update_organization` là false. Bản Blade cũ luôn
                 render biểu mẫu sửa được, kể cả cho vai trò "chỉ xem". --}}
            <div class="cart-alert" data-td-chixem hidden>
                Vai trò của bạn chỉ xem được thông tin tổ chức. Liên hệ quản trị
                viên của tổ chức để sửa.
            </div>

            <form class="wz-form" data-td-form-tochuc>
                <div class="wz-field">
                    <label>Tên công ty / Agency</label>
                    <input type="text" name="name" data-td-tc-name required disabled>
                    <div class="auth-error" data-td-loi-tc-name hidden></div>
                </div>
                <div class="wz-row">
                    <div class="wz-field">
                        <label>Email thanh toán</label>
                        <input type="email" name="billing_email" data-td-tc-email disabled placeholder="billing@company.com">
                        <div class="auth-error" data-td-loi-billing-email hidden></div>
                    </div>
                    <div class="wz-field">
                        <label>Số điện thoại</label>
                        <input type="text" name="billing_phone" data-td-tc-phone disabled placeholder="024-xxxx-xxxx">
                        <div class="auth-error" data-td-loi-billing-phone hidden></div>
                    </div>
                </div>
                <div class="wz-row">
                    <div class="wz-field">
                        <label>Mã số thuế</label>
                        <input type="text" name="tax_id" data-td-tc-tax disabled placeholder="0123456789">
                        <div class="auth-error" data-td-loi-tax-id hidden></div>
                    </div>
                    <div class="wz-field">
                        <label>Website</label>
                        <input type="url" name="website" data-td-tc-web disabled placeholder="https://company.com">
                        <div class="auth-error" data-td-loi-website hidden></div>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-p btn-sm" data-td-luu-tochuc disabled>Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var d  = document;
    var cf = JSON.parse(d.querySelector('[data-td-cauhinh]').textContent);

    var oCho       = d.querySelector('[data-td-cho]');
    var oThan      = d.querySelector('[data-td-than]');
    var oLoi       = d.querySelector('[data-td-loi]');
    var oThanhCong = d.querySelector('[data-td-thanhcong]');
    var oChiXem    = d.querySelector('[data-td-chixem]');

    var fHoSo   = d.querySelector('[data-td-form-hoso]');
    var fMatKhau = d.querySelector('[data-td-form-matkhau]');
    var fToChuc = d.querySelector('[data-td-form-tochuc]');

    // Ô nào của biểu mẫu nào — dùng cho cả điền giá trị lẫn bật/tắt.
    var O = {
        hoso: {
            name:  d.querySelector('[data-td-hoso-name]'),
            email: d.querySelector('[data-td-hoso-email]')
        },
        matkhau: {
            current_password:      d.querySelector('[data-td-mk-hientai]'),
            password:              d.querySelector('[data-td-mk-moi]'),
            password_confirmation: d.querySelector('[data-td-mk-xacnhan]')
        },
        tochuc: {
            name:          d.querySelector('[data-td-tc-name]'),
            billing_email: d.querySelector('[data-td-tc-email]'),
            billing_phone: d.querySelector('[data-td-tc-phone]'),
            tax_id:        d.querySelector('[data-td-tc-tax]'),
            website:       d.querySelector('[data-td-tc-web]')
        }
    };

    var NUT = {
        hoso:    d.querySelector('[data-td-luu-hoso]'),
        matkhau: d.querySelector('[data-td-luu-matkhau]'),
        tochuc:  d.querySelector('[data-td-luu-tochuc]')
    };

    // Ô lỗi theo tên trường. `name` xuất hiện ở HAI biểu mẫu nên chúng phải có
    // hai ô lỗi riêng — gộp lại thì lỗi của tổ chức hiện dưới ô tên cá nhân.
    var LOI = {
        hoso: {
            name:  d.querySelector('[data-td-loi-name]'),
            email: d.querySelector('[data-td-loi-email]')
        },
        matkhau: {
            current_password: d.querySelector('[data-td-loi-mk-hientai]'),
            password:         d.querySelector('[data-td-loi-password]')
        },
        tochuc: {
            name:          d.querySelector('[data-td-loi-tc-name]'),
            billing_email: d.querySelector('[data-td-loi-billing-email]'),
            billing_phone: d.querySelector('[data-td-loi-billing-phone]'),
            tax_id:        d.querySelector('[data-td-loi-tax-id]'),
            website:       d.querySelector('[data-td-loi-website]')
        }
    };

    /**
     * Trạng thái ban đầu do SCRIPT đặt, không chỉ dựa vào markup.
     *
     * Markup vẫn mang `hidden` và `disabled` — cần, để không có nhấp nháy giữa
     * lúc HTML về và lúc script chạy. Nhưng đặt lại ở đây là một việc khác:
     *
     *  - nếu có người gỡ `hidden` khỏi Blade, trang vẫn đúng;
     *  - và trạng thái này **test được**. Bộ khung jsdom dựng móc thành thẻ
     *    trống, không mang thuộc tính nào từ markup, nên một trạng thái ban đầu
     *    chỉ khai trong Blade thì phía JS không có cách nào canh.
     */
    oThan.hidden = true;
    oCho.hidden  = false;
    ['hoso', 'matkhau', 'tochuc'].forEach(function (t) { batTat(t, false); });

    function noi(el, chu) {
        if (! el) { return; }
        el.textContent = chu;
        el.hidden = ! chu;
    }

    function xoaLoi(ten) {
        var tap = LOI[ten] || {};
        Object.keys(tap).forEach(function (k) { noi(tap[k], ''); });
        oLoi.hidden = true;
    }

    /**
     * Lối đăng nhập lại khi 401.
     *
     * KHÔNG `location.reload()`: nếu phiên web còn mà phiên API thì không, tải
     * lại trang cho ra đúng trạng thái đó và vòng lặp không có lối ra. Đây là
     * một trong ba lỗi thật đã ghi ở `tests/js/README.md`.
     */
    function doiDangNhap() {
        oCho.hidden = true;
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
            if (r.status === 401) {
                doiDangNhap();
                return Promise.reject({ daXuLy: true });
            }

            return r.json().then(function (j) {
                if (! r.ok) { return Promise.reject(j || {}); }
                return j;
            });
        });
    }

    function batTat(ten, bat) {
        Object.keys(O[ten]).forEach(function (k) {
            if (O[ten][k]) { O[ten][k].disabled = ! bat; }
        });
        if (NUT[ten]) { NUT[ten].disabled = ! bat; }
    }

    function dien(data) {
        O.hoso.name.value  = data.user.name || '';
        O.hoso.email.value = data.user.email || '';

        var tc = data.organization || {};
        O.tochuc.name.value          = tc.name || '';
        O.tochuc.billing_email.value = tc.billing_email || '';
        O.tochuc.billing_phone.value = tc.billing_phone || '';
        O.tochuc.tax_id.value        = tc.tax_id || '';
        O.tochuc.website.value       = tc.website || '';

        // Bỏ `disabled` SAU khi điền, không trước: trước thì có một nhịp người
        // dùng gõ được vào ô mà giá trị chưa về, và lượt điền sẽ ghi đè.
        batTat('hoso', true);
        batTat('matkhau', true);

        var suaDuoc = !! (data.permissions && data.permissions.can_update_organization);
        batTat('tochuc', suaDuoc);
        oChiXem.hidden = suaDuoc;

        oCho.hidden  = true;
        oThan.hidden = false;
    }

    /** Lỗi validate về theo `details[]`; mỗi phần tử có `field` và `message`. */
    function veLoi(ten, kq) {
        xoaLoi(ten);

        var tap = LOI[ten] || {};
        var conLai = [];

        (kq.details || []).forEach(function (ct) {
            if (tap[ct.field]) {
                noi(tap[ct.field], ct.message);
            } else {
                conLai.push(ct.message);
            }
        });

        // Lỗi không thuộc ô nào (hoặc không phải lỗi validate) thì hiện ở đầu
        // trang — im lặng bỏ qua là để người dùng bấm Lưu mãi mà không biết vì
        // sao không được.
        if (! (kq.details || []).length || conLai.length) {
            oLoi.textContent = conLai.length ? conLai.join(' ') : (kq.message || 'Không lưu được.');
            oLoi.hidden = false;
        }
    }

    function gui(ten, url, truong, sauKhiXong) {
        var than = {};
        truong.forEach(function (k) { than[k] = O[ten][k] ? O[ten][k].value : ''; });

        batTat(ten, false);
        oThanhCong.hidden = true;
        xoaLoi(ten);

        goi(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(than)
        }).then(function (j) {
            oThanhCong.textContent = (j.data && j.data.message) || 'Đã lưu';
            oThanhCong.hidden = false;
            if (sauKhiXong) { sauKhiXong(); }
            batTat(ten, true);
        }).catch(function (kq) {
            if (kq && kq.daXuLy) { return; }
            veLoi(ten, kq || {});
            batTat(ten, true);
        });
    }

    fHoSo.addEventListener('submit', function (e) {
        e.preventDefault();
        gui('hoso', cf.apiHoSo, ['name', 'email']);
    });

    fMatKhau.addEventListener('submit', function (e) {
        e.preventDefault();
        gui('matkhau', cf.apiMatKhau, ['current_password', 'password', 'password_confirmation'], function () {
            // Xoá ô mật khẩu sau khi đổi xong: để nguyên là để mật khẩu mới
            // nằm trong DOM của một trang người dùng có thể bỏ đó mà đi.
            O.matkhau.current_password.value = '';
            O.matkhau.password.value = '';
            O.matkhau.password_confirmation.value = '';
        });
    });

    fToChuc.addEventListener('submit', function (e) {
        e.preventDefault();
        gui('tochuc', cf.apiToChuc, ['name', 'billing_email', 'billing_phone', 'tax_id', 'website']);
    });

    goi(cf.apiCaiDat)
        .then(function (j) { dien(j.data); })
        .catch(function (kq) {
            if (kq && kq.daXuLy) { return; }
            oCho.hidden = true;
            oLoi.textContent = (kq && kq.message) || 'Không tải được cài đặt.';
            oLoi.hidden = false;
        });
})();
</script>
@endsection
