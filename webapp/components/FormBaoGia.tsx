'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { bo, TOI_DA, xoaHet, type MucDaChon } from '@/lib/danh-sach-rut-gon';
import { dungDanhSach } from '@/lib/dung-danh-sach';
import { cauLoi, dungThan, gomTheoOwner, type ThongTinGui } from '@/lib/bao-gia';
import { dungCsv, tenTep } from '@/lib/tep-csv';
import { taiTep } from '@/lib/tai-tep';

/**
 * Màn hình gửi yêu cầu báo giá (lát 6).
 *
 * ══ Ba trạng thái, không bốn ══
 *
 * Danh sách rỗng → mời quay lại kho. Có danh sách → biểu mẫu. Gửi xong →
 * màn xác nhận. Không có trạng thái "đang gửi" riêng: nút tự khoá và đổi
 * chữ, vì một màn hình trắng giữa chừng làm người ta bấm lại.
 *
 * ══ Danh sách chỉ dọn SAU khi máy chủ nhận ══
 *
 * Dọn trước cho "sạch" là mất cả danh sách khi mạng rớt — người mua khoanh
 * ba mươi vị trí trong một tuần rồi mất trong một lần bấm. Thứ tự ở đây là:
 * máy chủ trả 201 → lưu mã tra cứu vào state → rồi mới `xoaHet()`.
 *
 * ══ Màn xác nhận nói đúng ba điều ══
 *
 * Gửi tới mấy media owner · mã tra cứu · và **không hứa thời gian**. Chủ dự
 * án chốt ngày 19/10/2026 rằng sàn không cam kết hạn trả lời, nên ở đây
 * không có câu "chúng tôi sẽ liên hệ sớm" — một lời hứa không đo được thì
 * tệ hơn là không hứa.
 */
export function FormBaoGia() {
    const ds = dungDanhSach();
    const nhom = gomTheoOwner(ds);

    const [tt, setTt] = useState<ThongTinGui>({
        contact_name: '',
        contact_email: '',
        contact_phone: '',
        contact_company: '',
        campaign_start: '',
        campaign_end: '',
        budget_vnd: '',
        notes: '',
        dong_y_chinh_sach: false,
    });

    const [dangGui, setDangGui] = useState(false);
    const [loi, setLoi] = useState<string | null>(null);
    const [xong, setXong] = useState<{
        ma: string;
        soViTri: number;
        soBiBo: number;
        soOwner: number;
        ds: MucDaChon[];
    } | null>(null);

    /** Ô mồi ẩn. Người thật không bao giờ điền; bot điền mọi ô nó thấy. */
    const moiRef = useRef<HTMLInputElement>(null);

    /**
     * Cờ chống gửi hai lần — **ref**, không phải state.
     *
     * Đo ngày 20/10/2026: bấm hai lần liên tiếp tạo **hai** yêu cầu, dù hàm
     * mở đầu bằng `if (dangGui) return`. `setDangGui(true)` không đổi
     * `dangGui` ngay — React gom cập nhật và render lại ở nhịp sau, nên cú
     * click thứ hai trong cùng nhịp vẫn đọc `false`.
     *
     * `dangGui` (state) vẫn giữ, nhưng để **vẽ**: khoá nút và đổi chữ. Hai
     * việc khác nhau, và dùng một thứ cho cả hai là lý do lỗi này tồn tại.
     */
    const khoaRef = useRef(false);

    /*
     * Điền sẵn khi đã đăng nhập.
     *
     * `/api/v2/me` trả 401 cho khách — đó là câu trả lời, không phải lỗi, nên
     * không hiện gì cả. Khách ẩn danh gửi được (chủ dự án chốt), và một thông
     * báo "bạn chưa đăng nhập" ở đây chỉ nói rằng họ đang thiếu thứ gì đó.
     */
    useEffect(() => {
        let huy = false;

        void (async () => {
            try {
                const res = await fetch('/api/v2/me', { headers: { Accept: 'application/json' } });

                if (!res.ok || huy) return;

                const body = (await res.json()) as {
                    data?: { name?: string; email?: string; organization?: { name?: string } | null };
                };

                if (huy || !body.data) return;

                setTt((cu) => ({
                    ...cu,
                    // Không ghi đè thứ người dùng đã gõ: dữ liệu về sau một
                    // nhịp, và giật chữ dưới tay người đang điền là tệ hơn
                    // việc không điền sẵn.
                    contact_name: cu.contact_name || (body.data?.name ?? ''),
                    contact_email: cu.contact_email || (body.data?.email ?? ''),
                    contact_company: cu.contact_company || (body.data?.organization?.name ?? ''),
                }));
            } catch {
                // Mất mạng lúc này không ảnh hưởng gì tới việc điền tay.
            }
        })();

        return () => {
            huy = true;
        };
    }, []);

    function doi(khoa: keyof ThongTinGui, giaTri: string | boolean) {
        setTt((cu) => ({ ...cu, [khoa]: giaTri }));
    }

    async function gui(e: React.FormEvent) {
        e.preventDefault();

        if (khoaRef.current) return;

        khoaRef.current = true;
        setDangGui(true);
        setLoi(null);

        const than = dungThan(ds, tt);

        // Ô mồi: gửi kèm khi có người (hay cái gì) điền vào nó. Máy chủ khai
        // `website` là `prohibited`, nên một yêu cầu có nó sẽ bị từ chối ở
        // đúng chỗ phải từ chối.
        const moi = moiRef.current?.value ?? '';
        if (moi !== '') than.website = moi;

        try {
            const res = await fetch('/api/v2/quote-requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(than),
            });

            const body = (await res.json().catch(() => null)) as
                | { data?: { code?: string; so_vi_tri?: number; so_bi_bo?: number } }
                | null;

            if (!res.ok) {
                setLoi(
                    res.status === 429
                        ? 'Bạn đã gửi khá nhiều yêu cầu trong một giờ. Vui lòng thử lại sau.'
                        : cauLoi(body, 'Không gửi được yêu cầu. Vui lòng thử lại.'),
                );

                return;
            }

            setXong({
                ma: body?.data?.code ?? '',
                soViTri: body?.data?.so_vi_tri ?? ds.length,
                soBiBo: body?.data?.so_bi_bo ?? 0,
                soOwner: nhom.length,

                // Giữ một bản của danh sách **trước khi dọn**: người mua vừa
                // gửi mười hai vị trí thường muốn chính danh sách đó cho hồ
                // sơ của họ, và sau `xoaHet()` thì không còn chỗ nào lấy ra.
                ds: [...ds],
            });

            // Dọn SAU khi máy chủ đã nhận, không trước.
            xoaHet();
        } catch {
            setLoi('Mất kết nối khi gửi. Danh sách của bạn vẫn còn — thử lại khi có mạng.');
        } finally {
            // Mở khoá để người dùng thử lại sau một lỗi. Khi gửi thành công
            // thì biểu mẫu đã nhường chỗ cho màn xác nhận, nên dòng này
            // không mở lại một nút nào còn trên màn hình.
            khoaRef.current = false;
            setDangGui(false);
        }
    }

    // ── Đã gửi xong ─────────────────────────────────────────────────────────

    if (xong) {
        return (
            <div className="bg-xong">
                <div className="bg-xong-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                    </svg>
                </div>

                <h2>Đã nhận yêu cầu báo giá</h2>

                <p className="bg-xong-ma">
                    Mã tra cứu: <strong>{xong.ma}</strong>
                </p>

                <p className="bg-xong-dong">
                    Yêu cầu gồm <strong>{xong.soViTri}</strong> vị trí, gửi tới{' '}
                    <strong>{xong.soOwner}</strong> media owner.
                    {xong.soBiBo > 0 ? (
                        <>
                            {' '}
                            {xong.soBiBo} vị trí trong danh sách không còn mở trên sàn nên không
                            được gửi.
                        </>
                    ) : null}
                </p>

                {/*
                  Không hứa thời gian: chủ dự án chốt sàn không cam kết hạn
                  trả lời. Câu dưới đây nói đúng cái đang xảy ra — không hơn.
                */}
                <p className="bg-xong-phu">
                    Media owner sẽ liên hệ trực tiếp qua email bạn để lại. Sàn không đặt hạn
                    trả lời, nên nếu cần gấp, bạn có thể hỏi thêm kèm mã tra cứu ở trên.
                </p>

                <div className="bg-xong-nut">
                    <button
                        type="button"
                        className="btn btn-s"
                        onClick={() => taiTep(dungCsv(xong.ds, window.location.origin), tenTep())}
                    >
                        Tải danh sách (CSV)
                    </button>

                    <Link href="/explore" className="btn btn-p">
                        Tiếp tục khám phá kho
                    </Link>
                </div>
            </div>
        );
    }

    // ── Chưa chọn gì ────────────────────────────────────────────────────────

    if (ds.length === 0) {
        return (
            <div className="bg-rong">
                <h2>Chưa có vị trí nào trong danh sách</h2>
                <p>
                    Tick vào các vị trí bạn muốn hỏi giá ở trang khám phá kho, rồi quay lại
                    đây.
                </p>
                <Link href="/explore" className="btn btn-p">
                    Sang trang khám phá kho
                </Link>
            </div>
        );
    }

    // ── Biểu mẫu ────────────────────────────────────────────────────────────

    return (
        <form className="bg-form" onSubmit={gui} noValidate>
            <div className="bg-cot">
                <section className="bg-khoi">
                    <h2 className="bg-h2">
                        {ds.length} vị trí · {nhom.length} media owner
                    </h2>

                    <p className="bg-ghi-chu">
                        Mỗi media owner chỉ thấy phần vị trí của họ, không thấy phần của
                        owner khác.
                    </p>

                    {nhom.map((n) => (
                        <div className="bg-nhom" key={n.owner}>
                            <div className="bg-nhom-ten">
                                {n.owner} <span>({n.muc.length})</span>
                            </div>

                            {n.muc.map((m) => (
                                <div className="bg-dong" key={m.slug}>
                                    <Link href={`/explore/${m.slug}`} className="bg-dong-ten">
                                        {m.ten}
                                    </Link>
                                    <span className="bg-dong-phu">{m.thanhPho ?? ''}</span>
                                    <button
                                        type="button"
                                        className="bg-dong-bo"
                                        onClick={() => bo(m.slug)}
                                        aria-label={`Bỏ ${m.ten} khỏi yêu cầu`}
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                        </div>
                    ))}
                </section>

                <section className="bg-khoi">
                    <h2 className="bg-h2">Thông tin liên hệ</h2>

                    <div className="bg-luoi">
                        <label className="bg-o">
                            <span>Họ tên *</span>
                            <input
                                type="text"
                                required
                                value={tt.contact_name}
                                onChange={(e) => doi('contact_name', e.target.value)}
                                autoComplete="name"
                            />
                        </label>

                        <label className="bg-o">
                            <span>Email *</span>
                            <input
                                type="email"
                                required
                                value={tt.contact_email}
                                onChange={(e) => doi('contact_email', e.target.value)}
                                autoComplete="email"
                            />
                        </label>

                        <label className="bg-o">
                            <span>Điện thoại</span>
                            <input
                                type="tel"
                                value={tt.contact_phone}
                                onChange={(e) => doi('contact_phone', e.target.value)}
                                autoComplete="tel"
                            />
                        </label>

                        <label className="bg-o">
                            <span>Đơn vị</span>
                            <input
                                type="text"
                                value={tt.contact_company}
                                onChange={(e) => doi('contact_company', e.target.value)}
                                autoComplete="organization"
                            />
                        </label>
                    </div>
                </section>

                <section className="bg-khoi">
                    <h2 className="bg-h2">Chiến dịch</h2>

                    <div className="bg-luoi">
                        <label className="bg-o">
                            <span>Từ ngày</span>
                            <input
                                type="date"
                                value={tt.campaign_start}
                                onChange={(e) => doi('campaign_start', e.target.value)}
                            />
                        </label>

                        <label className="bg-o">
                            <span>Đến ngày</span>
                            <input
                                type="date"
                                value={tt.campaign_end}
                                onChange={(e) => doi('campaign_end', e.target.value)}
                            />
                        </label>

                        <label className="bg-o">
                            <span>Ngân sách dự kiến (VND)</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                placeholder="500.000.000"
                                value={tt.budget_vnd}
                                onChange={(e) => doi('budget_vnd', e.target.value)}
                            />
                        </label>
                    </div>

                    <label className="bg-o bg-o-rong">
                        <span>Ghi chú cho media owner</span>
                        <textarea
                            rows={4}
                            maxLength={2000}
                            value={tt.notes}
                            onChange={(e) => doi('notes', e.target.value)}
                            placeholder="Mục tiêu chiến dịch, yêu cầu riêng về vị trí, thời lượng..."
                        />
                    </label>
                </section>
            </div>

            <aside className="bg-gui">
                <div className="bg-gui-trong">
                    <div className="bg-gui-dem">
                        <strong>{ds.length}</strong>/{TOI_DA} vị trí
                    </div>

                    <label className="bg-dong-y">
                        <input
                            type="checkbox"
                            checked={tt.dong_y_chinh_sach}
                            onChange={(e) => doi('dong_y_chinh_sach', e.target.checked)}
                        />
                        <span>
                            Tôi đồng ý để sàn và media owner liên hệ theo{' '}
                            <Link href="/chinh-sach-bao-mat" target="_blank">
                                chính sách bảo mật
                            </Link>
                            .
                        </span>
                    </label>

                    {/*
                      Ô mồi. `aria-hidden` + `tabIndex={-1}` để trình đọc màn
                      hình và phím Tab bỏ qua — người khiếm thị không được
                      rơi vào bẫy dành cho bot.
                    */}
                    <input
                        ref={moiRef}
                        type="text"
                        name="website"
                        className="bg-moi"
                        tabIndex={-1}
                        autoComplete="off"
                        aria-hidden="true"
                    />

                    {loi ? (
                        <div className="bg-loi" role="alert">
                            {loi}
                        </div>
                    ) : null}

                    <button
                        type="submit"
                        className="btn btn-p bg-nut"
                        disabled={dangGui || !tt.dong_y_chinh_sach}
                    >
                        {dangGui ? 'Đang gửi…' : 'Gửi yêu cầu báo giá'}
                    </button>

                    <p className="bg-gui-phu">
                        Không cần tài khoản. Media owner trả lời qua email bạn để lại.
                    </p>
                </div>
            </aside>
        </form>
    );
}
