@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Tạo Campaign | OOHX')

@section('content')
{{--
    Bước 1 của đặt chỗ — đọc giỏ và tạo chiến dịch qua `/api/v2`.

    ══ Không cần endpoint mới nào ══

    `GET /api/v2/cart` và `POST /api/v2/campaigns` đều đã có, và đường ghi dùng
    **đúng** `StoreCampaignRequest` mà bản Blade dùng — nên không có hai bộ luật
    validate.

    ══ Ba lý do tôi từng nêu về biểu mẫu, và chỗ chúng áp vào đây ══

     1. "ô nhập trống trong một nhịp" — **không áp**: đây là biểu mẫu TẠO MỚI,
        mọi ô vốn trống. Chỉ thanh bên (giỏ) cần khung chờ.
     2. "người dùng gõ được trong nhịp đó" — không áp, cùng lý do.
     3. "`old()` giữ lại thứ vừa nhập" — biểu mẫu gửi qua API nên không có vòng
        chuyển hướng; lỗi về theo `details[]` và hiện cạnh từng ô, giữ nguyên
        thứ người dùng đã gõ.

    Giỏ trống thì bản cũ `redirect()` về `/cart` ở controller. Vẫn vậy — phép
    kiểm đó ở máy chủ, không chuyển sang JS: để nó ở JS là hiện một trang tạo
    chiến dịch rồi mới đẩy người dùng đi.
--}}
@php
    $cauHinh = [
        'apiGio'      => url('/api/v2/cart'),
        'apiTao'      => url('/api/v2/campaigns'),
        'urlGio'      => route('buyer.cart'),
        'urlBuocSau'  => url('/booking'),
        'urlDangNhap' => url('/login'),
    ];
@endphp

<div class="w" style="padding-top:24px;padding-bottom:64px">
    <script type="application/json" data-tc-cauhinh>@json($cauHinh, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

    <div class="wz-steps">
        <div class="wz-step on"><div class="wz-step-n">1</div><div class="wz-step-l">Thông tin</div></div>
        <div class="wz-step-line"></div>
        <div class="wz-step"><div class="wz-step-n">2</div><div class="wz-step-l">Nội dung quảng cáo</div></div>
        <div class="wz-step-line"></div>
        <div class="wz-step"><div class="wz-step-n">3</div><div class="wz-step-l">Xác nhận</div></div>
    </div>

    <div class="wz-layout">
        <div class="wz-main">
            <h1 class="wz-title">Thông tin Campaign</h1>
            <p class="wz-sub">Nhập thông tin cơ bản về campaign của bạn</p>

            <div class="auth-error" style="margin-bottom:16px" data-tc-loi hidden></div>

            <form class="wz-form" data-tc-form>
                <div class="wz-field">
                    <label>Tên campaign <span style="color:var(--red)">*</span></label>
                    <input type="text" name="name" data-tc-name required placeholder="VD: Honda Civic Launch Q2 2026">
                    <div class="auth-error" data-tc-loi-name hidden></div>
                </div>
                <div class="wz-row">
                    <div class="wz-field">
                        <label>Brand / Nhãn hàng</label>
                        <input type="text" name="brand_name" data-tc-brand placeholder="VD: Honda Vietnam">
                        <div class="auth-error" data-tc-loi-brand-name hidden></div>
                    </div>
                    <div class="wz-field">
                        <label>Ngành hàng</label>
                        <select name="category" data-tc-category>
                            <option value="">Chọn ngành hàng</option>
                            <option value="automotive">Ô tô / Xe máy</option>
                            <option value="fmcg">FMCG</option>
                            <option value="tech">Công nghệ</option>
                            <option value="finance">Tài chính / Ngân hàng</option>
                            <option value="retail">Bán lẻ</option>
                            <option value="fnb">F&B</option>
                            <option value="real_estate">Bất động sản</option>
                            <option value="entertainment">Giải trí</option>
                            <option value="other">Khác</option>
                        </select>
                        <div class="auth-error" data-tc-loi-category hidden></div>
                    </div>
                </div>
                <div class="wz-field">
                    <label>Budget dự kiến (VNĐ)</label>
                    <input type="number" name="total_budget" data-tc-budget placeholder="VD: 50000000" min="0">
                    <div class="auth-error" data-tc-loi-total-budget hidden></div>
                </div>
                <div class="wz-field">
                    <label>Ghi chú</label>
                    <textarea name="notes" rows="3" data-tc-notes placeholder="Mục tiêu campaign, yêu cầu đặc biệt..."></textarea>
                    <div class="auth-error" data-tc-loi-notes hidden></div>
                </div>

                <div class="wz-actions">
                    <a href="{{ route('buyer.cart') }}" class="btn btn-s">Quay lại Plan</a>
                    <button type="submit" class="btn btn-p" data-tc-tiep>Tiếp tục <svg viewBox="0 0 24 24" fill="#fff" style="width:16px;height:16px"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></button>
                </div>
            </form>
        </div>

        <div class="wz-sidebar">
            <div class="cart-summary">
                <div class="cart-summary-title">Plan của bạn</div>

                <div data-tc-cho aria-hidden="true">
                    @for($i = 0; $i < 3; $i++)
                    <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--ln2);font-size:12px">
                        <div style="width:40px;height:40px;border-radius:6px;background:var(--bg2)"></div>
                        <div style="flex:1;min-width:0"><div>&nbsp;</div><div>&nbsp;</div></div>
                    </div>
                    @endfor
                </div>

                <div data-tc-ds hidden></div>

                <div class="cart-summary-total" data-tc-tong hidden>
                    <span>Tổng ước tính</span>
                    <span data-tc-tong-tien></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var d  = document;
    var cf = JSON.parse(d.querySelector('[data-tc-cauhinh]').textContent);

    var oLoi      = d.querySelector('[data-tc-loi]');
    var oCho      = d.querySelector('[data-tc-cho]');
    var oDs       = d.querySelector('[data-tc-ds]');
    var oTong     = d.querySelector('[data-tc-tong]');
    var oTongTien = d.querySelector('[data-tc-tong-tien]');
    var fTao      = d.querySelector('[data-tc-form]');
    var nutTiep   = d.querySelector('[data-tc-tiep]');

    var O = {
        name:         d.querySelector('[data-tc-name]'),
        brand_name:   d.querySelector('[data-tc-brand]'),
        category:     d.querySelector('[data-tc-category]'),
        total_budget: d.querySelector('[data-tc-budget]'),
        notes:        d.querySelector('[data-tc-notes]')
    };

    var LOI = {
        name:         d.querySelector('[data-tc-loi-name]'),
        brand_name:   d.querySelector('[data-tc-loi-brand-name]'),
        category:     d.querySelector('[data-tc-loi-category]'),
        total_budget: d.querySelector('[data-tc-loi-total-budget]'),
        notes:        d.querySelector('[data-tc-loi-notes]')
    };

    // Trạng thái ban đầu do SCRIPT đặt, không để markup giữ một mình: bộ khung
    // jsdom dựng mỗi móc thành một thẻ TRỐNG, không mang thuộc tính nào từ
    // Blade, nên thứ chỉ có trong markup là thứ không ca test nào chạm được.
    oDs.hidden   = true;
    oTong.hidden = true;
    oCho.hidden  = false;
    oLoi.hidden  = true;

    function tien(n) {
        return String(n == null ? 0 : n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /**
     * Ngày theo LỊCH, không qua múi giờ.
     *
     * `new Date('2026-10-08')` cho ra nửa đêm UTC, nên ở múi giờ âm nó lùi một
     * ngày. Đó là một trong ba lỗi thật đã ghi ở `tests/js/README.md`, nên ở
     * đây cắt chuỗi thay vì dựng `Date`.
     */
    function ngay(s) {
        if (! s) { return ''; }
        var p = String(s).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] : String(s);
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
            if (r.status === 401) { doiDangNhap(); return Promise.reject({ daXuLy: true }); }
            return r.json().then(function (j) { return r.ok ? j : Promise.reject(j || {}); });
        });
    }

    /** Thanh bên: ảnh, tên, kỳ chạy, tiền — đúng bốn thứ bản cũ hiện. */
    function veGio(data) {
        var ds = (data && data.items) || [];

        oDs.textContent = '';

        ds.forEach(function (it) {
            var hang = d.createElement('div');
            hang.setAttribute('style', 'display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--ln2);font-size:12px');

            var anh = d.createElement('img');
            // `setAttribute`, không nối chuỗi HTML: tên màn hình đến từ CSV của
            // media owner và có thể chứa dấu nháy. Một trong ba lỗi thật ở
            // `tests/js/README.md` là đúng chuyện đó.
            anh.setAttribute('src', (it.screen && it.screen.photo_url) || '');
            anh.setAttribute('style', 'width:40px;height:40px;border-radius:6px;object-fit:cover;background:var(--bg2)');
            anh.setAttribute('alt', '');

            var giua = d.createElement('div');
            giua.setAttribute('style', 'flex:1;min-width:0');

            var ten = d.createElement('div');
            ten.setAttribute('style', 'font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap');
            ten.textContent = (it.screen && it.screen.name) || '';

            var ky = d.createElement('div');
            ky.setAttribute('style', 'color:var(--t4)');
            var p = it.period || {};
            ky.textContent = ngay(p.start_date) + ' → ' + ngay(p.end_date);

            giua.appendChild(ten);
            giua.appendChild(ky);

            var gia = d.createElement('div');
            gia.setAttribute('style', 'font-weight:700;color:var(--t1);white-space:nowrap');
            gia.textContent = tien((it.estimate && it.estimate.cost) || 0) + '₫';

            hang.appendChild(anh);
            hang.appendChild(giua);
            hang.appendChild(gia);
            oDs.appendChild(hang);
        });

        // Tổng lấy từ `summary.subtotal` của MÁY CHỦ, không tự cộng lại ở
        // client. Bản cũ cộng `$items->sum('estimated_cost')` ở PHP; cộng lại ở
        // JS là mở ra đúng lỗi "VAT nhân hai lần" mà trang giỏ từng mắc.
        var s = (data && data.summary) || {};
        oTongTien.textContent = tien(s.subtotal) + ' ₫';

        oCho.hidden   = true;
        oDs.hidden    = false;
        oTong.hidden  = false;
    }

    fTao.addEventListener('submit', function (e) {
        e.preventDefault();

        xoaLoi();
        nutTiep.disabled = true;

        var than = {};
        Object.keys(O).forEach(function (k) {
            var v = O[k] ? O[k].value : '';
            if (v !== '') { than[k] = v; }
        });

        goi(cf.apiTao, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(than)
        }).then(function (j) {
            var id = j.data && j.data.campaign && j.data.campaign.id;

            if (! id) {
                oLoi.textContent = 'Máy chủ không trả về mã chiến dịch.';
                oLoi.hidden = false;
                nutTiep.disabled = false;
                return;
            }

            window.location.assign(cf.urlBuocSau + '/' + id + '/creative');
        }).catch(function (kq) {
            if (kq && kq.daXuLy) { return; }

            var conLai = [];

            ((kq && kq.details) || []).forEach(function (ct) {
                if (LOI[ct.field]) { noi(LOI[ct.field], ct.message); }
                else { conLai.push(ct.message); }
            });

            if (! ((kq && kq.details) || []).length || conLai.length) {
                oLoi.textContent = conLai.length ? conLai.join(' ') : ((kq && kq.message) || 'Không tạo được chiến dịch.');
                oLoi.hidden = false;
            }

            nutTiep.disabled = false;
        });
    });

    goi(cf.apiGio)
        .then(function (j) { veGio(j.data); })
        .catch(function (kq) {
            if (kq && kq.daXuLy) { return; }
            oCho.hidden = true;
            oLoi.textContent = (kq && kq.message) || 'Không tải được giỏ hàng.';
            oLoi.hidden = false;
        });
})();
</script>
@endsection
