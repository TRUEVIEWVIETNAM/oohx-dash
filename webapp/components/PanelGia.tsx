'use client';

import Link from 'next/link';
import { useMemo, useState } from 'react';
import type { ScreenDetail } from '@/lib/api';
import { NutThemVaoKeHoach } from './NutThemVaoKeHoach';

/**
 * Panel giá ở cột phải trang chi tiết.
 *
 * Dựng lại khối `bp-*` của `frontpage/detail.blade.php`: giá niêm yết, chọn
 * kiểu bán (I/O hoặc CPM) khi màn hình cho cả hai, ngày bắt đầu, thời lượng,
 * số màn hình, số CPM, tên brand, và một hàng tạm tính.
 *
 * ══ Khác bản Blade ở chỗ quan trọng nhất: con số nào là SỰ THẬT ══
 *
 * Bản Blade tính tiền bằng JS ngay trên trang — tạm tính, VAT theo
 * `config('pricing.vat_rate')`, rồi "Tổng cộng" — và trình bày cả ba như số
 * tiền thật. Chú thích trong chính view đó kể lại một lần nhãn và con số đã
 * lệch nhau (nhãn đọc config 8%, con số nhân cứng 0,1).
 *
 * CLAUDE.md mục 5: **số tiền do máy chủ tính**. Nên ở đây:
 *
 *  - Hàng tạm tính là **ước tính**, và nhãn nói đúng thế.
 *  - KHÔNG in VAT và KHÔNG in "Tổng cộng". Thuế suất không có trong hợp đồng
 *    API công khai, và viết cứng 8% vào mã trình duyệt là dựng một bản sao thứ
 *    hai của một chính sách — đúng loại bản sao đã gây ra lỗi trên.
 *  - Số tiền thật xuất hiện khi thêm vào giỏ, do `/api/v2/cart` trả về.
 *
 * ══ Nút "Thêm vào kế hoạch" — sửa 06/11/2026 ══
 *
 * Bản trước viết cứng một liên kết `/login` với nhãn "Đăng nhập để booking",
 * kèm lý do: *"Khu người mua chưa chuyển sang Next … Một nút gửi `POST` vào
 * đường chưa tồn tại thì tệ hơn một nút nói thật nó cần đăng nhập."*
 *
 * Nửa sau vẫn đúng và được giữ. Nửa đầu thì hết đúng: `POST /api/v2/cart/items`
 * đã có, cùng origin, nhận thẳng `screen_slug`. Cái giá của việc không sửa là
 * người **đã** đăng nhập — avatar hiện rõ ở thanh đầu — vẫn bị nút bảo đi
 * đăng nhập, và không có cách nào đặt chỗ từ trang họ đang đứng.
 *
 * Nút nay nằm ở `NutThemVaoKeHoach`, dùng chung với hai nút của trang gói.
 * Nhánh khách giữ nguyên từng chữ, và đó cũng là nhánh máy chủ render — xem
 * docblock của component đó về vì sao trạng thái đăng nhập không được vào HTML
 * đã cache.
 *
 * Ô **"Số màn hình"** không đi vào giỏ, và đó không phải sót:
 * `CartService::estimateCost()` lấy `screen_count` từ
 * `inventory->effective_screen_count` kèm chú thích "screen_count KHÔNG nhận
 * từ client". Nên ô ấy chỉ đổi con số ước tính trên panel này. Nó là một lệch
 * có sẵn mà việc nối nút làm lộ ra, và là một việc riêng.
 */

type KieuBan = 'io' | 'cpm';

function tien(n: number, donVi: string): string {
    if (donVi === 'VND') {
        return new Intl.NumberFormat('vi-VN').format(Math.round(n)) + ' ₫';
    }

    return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(n) + ' ' + donVi;
}

/** `YYYY-MM-DD` của hôm nay + n ngày, tính ở UTC để máy chủ và trình duyệt khớp. */
function ngaySau(n: number): string {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

/** Số ngày của cửa sổ CPM khi người mua không chọn khoảng. */
const NGAY_CUA_SO_CPM = 30;

/**
 * Ngày kết thúc của `soKy` kỳ kể từ `batDau`, theo đúng cách máy chủ đếm kỳ.
 *
 * ══ Vì sao trừ một ngày ══
 *
 * `BillablePeriodCalculator::calendarMonths()` đếm mỗi kỳ là
 * `[mốc, mốc + 1 tháng - 1 ngày]`, và docblock của nó nêu thẳng ví dụ:
 * *"15/01 – 14/02 = 1 kỳ. 15/01 – 15/02 = 2 kỳ."* Gửi `end = start + n tháng`
 * tròn trĩnh là tự khai thêm một kỳ, tức **trả tiền gấp đôi cho tháng cuối**
 * ở một đơn một kỳ. Nhánh tuần cũng vậy: `ceil(days / 7)` với `days` tính cả
 * hai đầu mút.
 *
 * Tính ở UTC, cùng lý do `ngaySau()`: hai bên phải đọc ra cùng một ngày lịch.
 * `setUTCMonth` của JS tự tràn (31/01 + 1 tháng = 02/03) trong khi máy chủ
 * dùng `addMonthsNoOverflow` (→ 28/02), nên bước lùi về cuối tháng đích là
 * bắt buộc, không phải làm đẹp.
 */
function ngayKetThuc(batDau: string, soKy: number, theoTuan: boolean): string {
    const d = new Date(`${batDau}T00:00:00Z`);

    if (Number.isNaN(d.getTime())) return batDau;

    if (theoTuan) {
        d.setUTCDate(d.getUTCDate() + soKy * 7 - 1);

        return d.toISOString().slice(0, 10);
    }

    const ngayGoc = d.getUTCDate();

    d.setUTCDate(1);
    d.setUTCMonth(d.getUTCMonth() + soKy);

    // Tháng đích ngắn hơn: 31/01 + 1 tháng phải là 28/02, không phải 02/03.
    const ngayCuoiThangDich = new Date(
        Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0),
    ).getUTCDate();

    d.setUTCDate(Math.min(ngayGoc, ngayCuoiThangDich));
    d.setUTCDate(d.getUTCDate() - 1);

    return d.toISOString().slice(0, 10);
}

export function PanelGia({ screen }: { screen: ScreenDetail }) {
    const ioRate = screen.pricing?.io_rate?.amount ?? 0;
    const ioUnit = screen.pricing?.io_rate?.unit ?? 'month';
    const cpmRate = screen.pricing?.floor_cpm?.amount ?? 0;
    const cpmCurrency = screen.pricing?.floor_cpm?.currency ?? 'VND';
    const model = screen.pricing?.model ?? 'io';

    const choIo = model === 'io' || model === 'both';
    const choCpm = model === 'cpm' || model === 'both';
    const caHai = model === 'both';

    const coGia = (choIo && ioRate > 0) || (choCpm && cpmRate > 0);

    const theoTuan = ioUnit === 'week';
    const nhanKy = theoTuan ? 'tuần' : 'tháng';

    const [kieu, setKieu] = useState<KieuBan>(choIo && ioRate > 0 ? 'io' : 'cpm');
    const [batDau, setBatDau] = useState(() => ngaySau(7));
    const [soKy, setSoKy] = useState(theoTuan ? 4 : 3);
    const [soManHinh, setSoManHinh] = useState(1);
    const [soCpm, setSoCpm] = useState(1000);
    const [brand, setBrand] = useState('');

    const tamTinh = useMemo(() => {
        if (kieu === 'cpm') {
            return { so: cpmRate * soCpm, donVi: cpmCurrency, nhan: `${tien(cpmRate, cpmCurrency)} × ${new Intl.NumberFormat('vi-VN').format(soCpm)} CPM` };
        }

        return {
            so: ioRate * soManHinh * soKy,
            donVi: 'VND',
            nhan: `${tien(ioRate, 'VND')} × ${soManHinh} mh × ${soKy} ${nhanKy}`,
        };
    }, [kieu, cpmRate, soCpm, cpmCurrency, ioRate, soManHinh, soKy, nhanKy]);

    const conTrong = screen.availability?.remaining_sov_pct ?? 0;
    const soNgay = screen.availability?.window_days ?? 30;
    const duongDangNhap = `/login?next=${encodeURIComponent(`/explore/${screen.slug}`)}`;

    const ngayKetThucCpm = useMemo(() => {
        const d = new Date(`${batDau}T00:00:00Z`);

        if (Number.isNaN(d.getTime())) return batDau;

        d.setUTCDate(d.getUTCDate() + NGAY_CUA_SO_CPM - 1);

        return d.toISOString().slice(0, 10);
    }, [batDau]);

    if (!coGia) {
        return (
            <div className="bp bp-quote">
                <div className="bp-head">
                    <div className="bp-quote-ic">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" />
                        </svg>
                    </div>
                    <div className="bp-quote-title">Liên hệ báo giá</div>
                    <div className="bp-quote-sub">
                        Vị trí này chưa công khai giá. Liên hệ media owner để nhận báo giá chi tiết.
                    </div>

                    {conTrong > 0 ? (
                        <div className="bp-avail" style={{ marginTop: 10 }}>
                            <div className="bp-dot" />
                            Còn {conTrong}% thời lượng trong {soNgay} ngày tới
                        </div>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div className="bp">
            <div className="bp-head">
                {caHai ? (
                    <>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'baseline', flexWrap: 'wrap' }}>
                            <div className="bp-price">{tien(ioRate, 'VND')}</div>
                            <div style={{ fontSize: 13, color: 'var(--t4)' }}>
                                / {theoTuan ? 'mh/tuần' : 'mh/tháng'}
                            </div>
                        </div>
                        <div style={{ fontSize: 12, color: 'var(--t3)', margin: '4px 0 2px' }}>
                            hoặc <strong>{tien(cpmRate, cpmCurrency)}/CPM</strong>
                        </div>
                    </>
                ) : choCpm ? (
                    <>
                        <div className="bp-price">{tien(cpmRate, cpmCurrency)}</div>
                        <div style={{ fontSize: 13, color: 'var(--t4)', margin: '4px 0 10px' }}>
                            / CPM · Chưa bao gồm VAT
                        </div>
                    </>
                ) : (
                    <>
                        <div className="bp-price">{tien(ioRate, 'VND')}</div>
                        <div style={{ fontSize: 13, color: 'var(--t4)', margin: '4px 0 10px' }}>
                            / {theoTuan ? 'màn hình/tuần' : 'màn hình/tháng'} · Chưa bao gồm VAT
                        </div>
                    </>
                )}

                {screen.specs?.io_kpi_spots_per_day && choIo ? (
                    <div style={{ fontSize: 12, color: 'var(--bl)', fontWeight: 600, margin: '4px 0 8px' }}>
                        KPI: {new Intl.NumberFormat('vi-VN').format(screen.specs.io_kpi_spots_per_day)}{' '}
                        spots/màn hình/ngày
                    </div>
                ) : null}

                {conTrong > 0 ? (
                    <div className="bp-avail">
                        <div className="bp-dot" />
                        Còn {conTrong}% thời lượng trong {soNgay} ngày tới
                    </div>
                ) : null}
            </div>

            <div className="bp-body">
                {caHai ? (
                    <div className="bp-field">
                        <div className="bp-lbl">Kiểu booking</div>
                        <div className="bp-toggle">
                            <button
                                type="button"
                                className={kieu === 'io' ? 'bp-tog-btn bp-tog-on' : 'bp-tog-btn'}
                                onClick={() => setKieu('io')}
                            >
                                I/O Booking
                            </button>
                            <button
                                type="button"
                                className={kieu === 'cpm' ? 'bp-tog-btn bp-tog-on' : 'bp-tog-btn'}
                                onClick={() => setKieu('cpm')}
                            >
                                CPM
                            </button>
                        </div>
                    </div>
                ) : null}

                <div className="bp-field">
                    <div className="bp-lbl">Ngày bắt đầu</div>
                    <input
                        className="bp-inp"
                        type="date"
                        value={batDau}
                        min={ngaySau(0)}
                        onChange={(e) => setBatDau(e.target.value)}
                    />
                </div>

                {kieu === 'io' ? (
                    <>
                        <div className="bp-field">
                            <div className="bp-lbl">Thời lượng</div>
                            <select
                                className="bp-inp"
                                value={soKy}
                                onChange={(e) => setSoKy(Number(e.target.value))}
                            >
                                {(theoTuan ? [1, 2, 4, 8, 12] : [1, 2, 3, 6, 12]).map((n) => (
                                    <option value={n} key={n}>
                                        {n} {nhanKy}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="bp-field">
                            <div className="bp-lbl">Số màn hình</div>
                            <input
                                className="bp-inp"
                                type="number"
                                min={1}
                                value={soManHinh}
                                onChange={(e) => setSoManHinh(Math.max(1, Number(e.target.value) || 1))}
                            />
                        </div>
                    </>
                ) : (
                    <div className="bp-field">
                        <div className="bp-lbl">Số CPM (× 1.000 lượt hiển thị)</div>
                        <input
                            className="bp-inp"
                            type="number"
                            min={1}
                            value={soCpm}
                            onChange={(e) => setSoCpm(Math.max(1, Number(e.target.value) || 1))}
                        />
                    </div>
                )}

                <div className="bp-field">
                    <div className="bp-lbl">Brand / Campaign</div>
                    <input
                        className="bp-inp"
                        type="text"
                        placeholder="VD: Honda Civic Launch Q2"
                        value={brand}
                        onChange={(e) => setBrand(e.target.value)}
                    />
                </div>

                <div className="bp-sum">
                    <div className="bp-row">
                        <span className="bp-rl">{tamTinh.nhan}</span>
                        <span className="bp-rv">{tien(tamTinh.so, tamTinh.donVi)}</span>
                    </div>

                    {/*
                      Không có hàng "VAT" và hàng "Tổng cộng" như bản Blade —
                      xem docblock. Dòng dưới đây nói ra điều mà ba hàng kia
                      ngầm hứa sai: con số trên là ước tính.
                    */}
                    <div className="bp-row">
                        <span className="bp-rl" style={{ fontSize: 12, color: 'var(--t4)' }}>
                            Ước tính, chưa gồm VAT. Số tiền chính thức do hệ thống tính khi bạn thêm
                            vào kế hoạch.
                        </span>
                    </div>
                </div>

                <div className="bp-ctas">
                    <NutThemVaoKeHoach
                        duongDangNhap={duongDangNhap}
                        /*
                         * Tính lúc bấm, không lúc render: ngày bắt đầu, kiểu
                         * bán, số kỳ và số CPM đều là state người dùng đang
                         * sửa.
                         *
                         * `duration_units` và `quantity` cố ý vắng — xem
                         * docblock của `lib/gui-len-laravel.ts`. Máy chủ suy số kỳ từ
                         * khoảng ngày, và `screen_count` nó lấy từ cấu hình
                         * kho chứ không nhận từ client.
                         */
                        than={() =>
                            kieu === 'cpm'
                                ? {
                                      screen_slug: screen.slug ?? '',
                                      start_date: batDau,
                                      // Panel CPM không hỏi khoảng ngày, nên
                                      // dùng đúng cửa sổ mặc định của máy chủ
                                      // (`CartService` cũng lấy +30 ngày khi
                                      // thiếu). Khoảng này quyết định số CPM
                                      // tối thiểu, nên tự chọn một cửa sổ ngắn
                                      // hơn để "dễ qua" là lách chính phép
                                      // kiểm đó.
                                      end_date: ngayKetThucCpm,
                                      pricing_model: 'cpm',
                                      booked_cpms: soCpm,
                                  }
                                : {
                                      screen_slug: screen.slug ?? '',
                                      start_date: batDau,
                                      end_date: ngayKetThuc(batDau, soKy, theoTuan),
                                      pricing_model: 'io',
                                  }
                        }
                    >
                        <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true" style={{ width: 18, height: 18 }}>
                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                        </svg>{' '}
                    </NutThemVaoKeHoach>
                </div>
            </div>

            {/*
              Chân panel. Bản Blade viết "Thanh toán bảo mật bởi OOHX · Hoàn
              tiền 100% nếu hủy trước 48h" — KHÔNG dựng lại nguyên văn: sàn
              **không thu hộ** (người mua chuyển khoản trực tiếp cho media
              owner, hồ sơ Bộ Công Thương), nên "thanh toán bảo mật bởi OOHX"
              nói sai cách tiền chạy; và chính sách hủy/hoàn tiền là một trong
              sáu câu hỏi nghiệp vụ còn chờ chủ dự án chốt, nên "hoàn tiền 100%
              nếu hủy trước 48h" là một cam kết chưa ai duyệt.
            */}
            <div className="bp-foot">
                <svg viewBox="0 0 24 24" fill="var(--grn)" aria-hidden="true" style={{ width: 14, height: 14 }}>
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
                </svg>{' '}
                Điều khoản và phí nền tảng công khai tại{' '}
                <Link href="/bang-phi" style={{ color: 'var(--bl)' }}>
                    bảng phí
                </Link>
            </div>
        </div>
    );
}
