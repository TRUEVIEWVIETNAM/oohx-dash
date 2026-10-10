@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Xác nhận đặt chỗ | OOHX')

@section('content')
{{--
    Bước 3 của đặt chỗ — trang người mua CAM KẾT nghĩa vụ tiền.

    ══ Chuyển sang API sửa đúng lỗi mà đường này tồn tại để chặn ══

    Bản Blade cũ tự tính VAT NGAY TRONG VIEW:

        $lines->sum('estimated_cost') * config('pricing.vat_rate')
        $lines->sum('estimated_cost') * (1 + config('pricing.vat_rate'))

    Đó là đúng hình dạng lỗi "VAT nhân hai lần" mà trang giỏ từng mắc và là lý
    do mốc 3 tồn tại. Nay ba con số `subtotal`, `vat`, `total` lấy thẳng từ
    `summary` của máy chủ, nơi VAT tính một chỗ duy nhất
    (`PaymentService::withVat()`).

    ══ Một dòng bị BỎ, có chủ ý ══

    Bản cũ in `CPM {{ $line->floor_cpm_at_booking }}` dưới mỗi dòng.
    `BookingLineResource` **cố ý loại** ba cột ảnh-chụp-giá đó, kèm lý do:
    "đặt một đơn giá chưa nhân bên cạnh một tổng đã nhân là mời người đọc so
    hai số không so được với nhau". Nên dòng đó bỏ, không nới DTO để lấy lại.

    ══ `can_submit` do máy chủ trả lời ══

    Không để client tự suy từ `status` và `conflicts`. Giao diện ẩn nút không
    phải phân quyền, nhưng giao diện tự đoán điều kiện thì lệch là chắc chắn —
    và `POST submit` vẫn kiểm lại xung đột ở máy chủ.
--}}
@php
    $cauHinh = [
        'api'         => url('/api/v2/campaigns/' . $campaign->id),
        'apiGui'      => url('/api/v2/campaigns/' . $campaign->id . '/submit'),
        'urlXem'      => route('buyer.campaigns.show', $campaign),
        'urlDangNhap' => url('/login'),
        'urlQuyChe'   => url('/quy-che-hoat-dong'),
        'urlBaoMat'   => url('/chinh-sach-bao-mat'),
    ];
@endphp

<div class="w" style="padding-top:24px;padding-bottom:64px">
    <script type="application/json" data-xn-cauhinh>@json($cauHinh, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>

    <div class="wz-steps">
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Thông tin</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Nội dung quảng cáo</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step on"><div class="wz-step-n">3</div><div class="wz-step-l">Xác nhận</div></div>
    </div>

    <h1 class="wz-title">Xác nhận và gửi đặt chỗ</h1>
    <p class="wz-sub" data-xn-tieude>&nbsp;</p>

    <div class="auth-error" style="margin-bottom:16px" data-xn-loi hidden></div>

    <div class="wz-conflict" data-xn-xungdot hidden>
        <div style="font-weight:700;margin-bottom:6px">Cảnh báo: xung đột SOV</div>
        <div data-xn-xungdot-ds></div>
    </div>

    <div data-xn-cho aria-hidden="true">
        @for($i = 0; $i < 2; $i++)
        <div class="wz-card" style="margin-bottom:16px"><div class="wz-card-title">&nbsp;</div><div>&nbsp;</div><div>&nbsp;</div></div>
        @endfor
    </div>

    <div class="wz-layout" data-xn-than hidden>
        <div class="wz-main">
            <div class="wz-card">
                <div class="wz-card-title">Thông tin chiến dịch</div>
                <div class="wz-info-grid">
                    <div class="wz-info-item"><div class="wz-info-l">Tên</div><div class="wz-info-v" data-xn-ten></div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Nhãn hàng</div><div class="wz-info-v" data-xn-brand></div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Ngành hàng</div><div class="wz-info-v" data-xn-nganh></div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Thời gian</div><div class="wz-info-v" data-xn-ky></div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Ngân sách</div><div class="wz-info-v" data-xn-ngansach></div></div>
                </div>
            </div>

            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title" data-xn-dong-tieude></div>
                <div class="wz-lines" data-xn-dong></div>
            </div>

            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title" data-xn-nd-tieude></div>
                <div data-xn-nd-trong hidden style="font-size:13px;color:var(--t4);padding:12px 0">Chưa tải nội dung quảng cáo. Bạn có thể tải sau khi chiến dịch được duyệt.</div>
                <div class="wz-creative-list" data-xn-nd></div>
            </div>
        </div>

        <div class="wz-sidebar">
            <div class="cart-summary">
                <div class="cart-summary-title">Tổng kết đặt chỗ</div>
                <div class="cart-summary-row"><span>Số màn hình</span><span style="font-weight:700" data-xn-soman></span></div>
                <div class="cart-summary-row"><span>Tổng ước tính</span><span style="font-weight:700" data-xn-truocvat></span></div>
                <div class="cart-summary-row"><span>VAT</span><span data-xn-vat></span></div>
                <div class="cart-summary-total">
                    <span>Tổng cộng</span>
                    <span data-xn-tong></span>
                </div>

                <form style="margin-top:16px" data-xn-form hidden>
                    <label class="consent">
                        <input type="checkbox" name="confirm_accuracy" value="1" data-xn-xacnhan required>
                        <span>Tôi xác nhận thông tin đặt chỗ chính xác. Đặt chỗ sẽ được gửi đến media owner để phê duyệt trong vòng 48h.</span>
                    </label>
                    <label class="consent">
                        <input type="checkbox" name="accept_terms" value="1" data-xn-dongy required>
                        <span>
                            Tôi đã đọc và đồng ý với
                            <a href="{{ url('/quy-che-hoat-dong') }}" target="_blank" rel="noopener">Quy chế hoạt động</a>
                            và
                            <a href="{{ url('/chinh-sach-bao-mat') }}" target="_blank" rel="noopener">Chính sách bảo mật</a>
                        </span>
                    </label>
                    <button type="submit" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;height:48px;font-size:15px" data-xn-gui>
                        <svg viewBox="0 0 24 24" fill="#fff" style="width:18px;height:18px"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        Gửi đặt chỗ
                    </button>
                </form>

                <div data-xn-chan hidden style="margin-top:16px;padding:12px;background:rgba(255,59,48,.06);border-radius:10px;font-size:12px;color:var(--red);text-align:center">
                    Không gửi được đặt chỗ — có xung đột SOV
                </div>
            </div>

            <div class="wz-actions-card" style="margin-top:12px">
                <a href="{{ route('buyer.booking.creative', $campaign) }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">Quay lại</a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var d  = document;
    var cf = JSON.parse(d.querySelector('[data-xn-cauhinh]').textContent);

    var oCho  = d.querySelector('[data-xn-cho]');
    var oThan = d.querySelector('[data-xn-than]');
    var oLoi  = d.querySelector('[data-xn-loi]');
    var oXD   = d.querySelector('[data-xn-xungdot]');
    var oXDDs = d.querySelector('[data-xn-xungdot-ds]');
    var fGui  = d.querySelector('[data-xn-form]');
    var oChan = d.querySelector('[data-xn-chan]');
    var nutGui = d.querySelector('[data-xn-gui]');

    // Trạng thái ban đầu do SCRIPT đặt, không để markup giữ một mình: bộ khung
    // jsdom dựng mỗi móc thành một thẻ TRỐNG, không mang thuộc tính nào từ
    // Blade, nên thứ chỉ có trong markup là thứ không ca test nào chạm được.
    oThan.hidden = true;
    oCho.hidden  = false;
    oLoi.hidden  = true;
    oXD.hidden   = true;
    fGui.hidden  = true;
    oChan.hidden = true;

    function tien(n) {
        return String(n == null ? 0 : n).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' ₫';
    }

    /** Ngày theo LỊCH — `new Date('2026-10-08')` lùi một ngày ở múi giờ âm. */
    function ngay(s, daiNgay) {
        if (! s) { return ''; }
        var p = String(s).split('-');
        if (p.length !== 3) { return String(s); }
        return daiNgay ? p[2] + '/' + p[1] + '/' + p[0] : p[2] + '/' + p[1];
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

    function veXungDot(ds) {
        oXDDs.textContent = '';

        if (! ds.length) { oXD.hidden = true; return; }

        ds.forEach(function (c) {
            var dong = d.createElement('div');
            // `requested_pct` / `available_pct` — tên của DTO. Bản Blade cũ đọc
            // `requested` / `available`, tức tên của service; DTO đổi tên để
            // nói rõ đơn vị là phần trăm.
            dong.textContent = (c.screen_name || '') + ' (' + (c.dates || '') + '): yêu cầu '
                + c.requested_pct + '%, còn trống ' + c.available_pct + '%';
            oXDDs.appendChild(dong);
        });

        oXD.hidden = false;
    }

    function veDong(lines) {
        var oDs = d.querySelector('[data-xn-dong]');
        oDs.textContent = '';
        d.querySelector('[data-xn-dong-tieude]').textContent = 'Danh sách màn hình (' + lines.length + ')';

        lines.forEach(function (l) {
            var s = l.screen || {};
            var hang = d.createElement('div');
            hang.className = 'wz-line';

            var khungAnh = d.createElement('div');
            khungAnh.className = 'wz-line-img';
            var anh = d.createElement('img');
            // `setAttribute`, không nối chuỗi HTML — tên và URL đến từ CSV của
            // media owner.
            anh.setAttribute('src', s.photo_url || '');
            anh.setAttribute('alt', '');
            khungAnh.appendChild(anh);

            var than = d.createElement('div');
            than.className = 'wz-line-body';

            var ten = d.createElement('div');
            ten.setAttribute('style', 'font-weight:700;color:var(--t1);font-size:13px');
            ten.textContent = s.name || '';

            var phu = d.createElement('div');
            phu.setAttribute('style', 'font-size:11px;color:var(--t4)');
            phu.textContent = ((s.owner && s.owner.name) || '') + ' · ' + ((s.location && s.location.city) || '');

            var ct = d.createElement('div');
            ct.setAttribute('style', 'font-size:12px;color:var(--t3);margin-top:4px');
            var p = l.period || {};
            var dl = l.delivery || {};
            ct.textContent = ngay(p.start_date) + ' → ' + ngay(p.end_date)
                + ' · SOV ' + (dl.share_of_voice_pct == null ? '—' : dl.share_of_voice_pct) + '%'
                + ' · ' + (dl.spot_length == null ? '—' : dl.spot_length) + 's';

            than.appendChild(ten);
            than.appendChild(phu);
            than.appendChild(ct);

            var phai = d.createElement('div');
            phai.setAttribute('style', 'text-align:right;white-space:nowrap');
            var gia = d.createElement('div');
            gia.setAttribute('style', 'font-size:14px;font-weight:800;color:var(--t1)');
            gia.textContent = tien((l.estimate && l.estimate.cost) || 0);
            phai.appendChild(gia);

            hang.appendChild(khungAnh);
            hang.appendChild(than);
            hang.appendChild(phai);
            oDs.appendChild(hang);
        });
    }

    function veNoiDung(ds) {
        var oNd = d.querySelector('[data-xn-nd]');
        oNd.textContent = '';
        d.querySelector('[data-xn-nd-tieude]').textContent = 'Nội dung quảng cáo (' + ds.length + ')';
        d.querySelector('[data-xn-nd-trong]').hidden = ds.length > 0;

        ds.forEach(function (c) {
            var muc = d.createElement('div');
            muc.className = 'wz-creative-item';

            var anh = d.createElement('div');
            anh.className = 'wz-creative-thumb';

            if (c.type === 'image' && c.download_url) {
                var img = d.createElement('img');
                img.setAttribute('src', c.download_url);
                img.setAttribute('alt', '');
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
            var loai = d.createElement('div');
            loai.setAttribute('style', 'font-size:11px;color:var(--t4)');
            // `type_label` của máy chủ — `vast_tag` hoa lên thành `VAST_TAG`.
            loai.textContent = c.type_label || c.type || '';
            tt.appendChild(ten);
            tt.appendChild(loai);

            muc.appendChild(anh);
            muc.appendChild(tt);
            oNd.appendChild(muc);
        });
    }

    function ve(data) {
        var c = data.campaign || {};
        var s = data.summary || {};
        var p = c.period || {};

        d.querySelector('[data-xn-tieude]').textContent = (c.name || '') + ' · ' + (c.code || '');
        d.querySelector('[data-xn-ten]').textContent    = c.name || '—';
        d.querySelector('[data-xn-brand]').textContent  = c.brand_name || '—';
        d.querySelector('[data-xn-nganh]').textContent  = c.category || '—';
        d.querySelector('[data-xn-ky]').textContent     = ngay(p.start_date, true) + ' → ' + ngay(p.end_date, true);
        d.querySelector('[data-xn-ngansach]').textContent = c.budget ? tien(c.budget) : '—';

        veXungDot(data.conflicts || []);
        veDong(data.lines || []);
        veNoiDung(data.creatives || []);

        // Ba con số lấy THẲNG từ máy chủ. Không nhân lại `vat_rate` ở đây —
        // đó là lỗi bản cũ.
        d.querySelector('[data-xn-soman]').textContent   = s.line_count == null ? '—' : s.line_count;
        d.querySelector('[data-xn-truocvat]').textContent = tien(s.subtotal);
        d.querySelector('[data-xn-vat]').textContent     = tien(s.vat);
        d.querySelector('[data-xn-tong]').textContent    = tien(s.total);

        // `can_submit` do máy chủ quyết, không suy từ `conflicts` ở client.
        fGui.hidden  = ! s.can_submit;
        oChan.hidden = !! s.can_submit;

        oCho.hidden  = true;
        oThan.hidden = false;
    }

    fGui.addEventListener('submit', function (e) {
        e.preventDefault();

        oLoi.hidden = true;
        nutGui.disabled = true;

        goi(cf.apiGui, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                confirm_accuracy: d.querySelector('[data-xn-xacnhan]').checked ? 1 : 0,
                accept_terms:     d.querySelector('[data-xn-dongy]').checked ? 1 : 0
            })
        }).then(function () {
            window.location.assign(cf.urlXem);
        }).catch(function (kq) {
            if (kq && kq.daXuLy) { return; }

            var ct = ((kq && kq.details) || []).map(function (x) { return x.message; });
            oLoi.textContent = ct.length ? ct.join(' ') : ((kq && kq.message) || 'Không gửi được đặt chỗ.');
            oLoi.hidden = false;
            nutGui.disabled = false;
        });
    });

    goi(cf.api)
        .then(function (j) { ve(j.data); })
        .catch(function (kq) {
            if (kq && kq.daXuLy) { return; }
            oCho.hidden = true;
            oLoi.textContent = (kq && kq.message) || 'Không tải được chiến dịch.';
            oLoi.hidden = false;
        });
})();
</script>
@endsection
