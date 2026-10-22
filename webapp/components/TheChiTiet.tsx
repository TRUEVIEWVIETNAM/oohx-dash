'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import type { ScreenDetail } from '@/lib/api';
import { PanelGia } from '@/components/PanelGia';
import { OTick } from '@/components/OTick';
import { dungKeoSheet } from '@/lib/dung-keo-sheet';

/**
 * Thẻ chi tiết mở **cạnh** danh sách — lát 5 của
 * `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * ══ Vì sao cạnh chứ không đè ══
 *
 * Đo Google Maps thật ở 1440×900 (21/10/2026): thẻ kết quả ở x=72 rộng 408,
 * thẻ chi tiết ở x=496 rộng 408, và bản đồ **còn thấy 536px**. Chi tiết mở ra
 * không lấy mất chỗ người dùng đang đứng trong danh sách — họ so sánh được
 * vị trí thứ ba với vị trí thứ bảy mà không phải nhớ.
 *
 * Ở OOHX panel danh sách bắt đầu ở 16px và rộng 400, nên thẻ này ở
 * **left:428px**: 16 + 400 + 12 khe.
 *
 * ══ URL đổi, và đó là điều kiện để trang chi tiết còn được index ══
 *
 * Mở thẻ thì địa chỉ thành `/explore/{slug}` và nút Back đóng nó. Hai hệ quả,
 * cả hai đều cần:
 *
 *  - chia sẻ được: dán địa chỉ cho đồng nghiệp thì họ mở ra **trang chi tiết
 *    đầy đủ** do máy chủ dựng, không phải một trang danh sách trống;
 *  - Google vẫn bò vào `/explore/{slug}` như cũ và nhận đúng HTML cũ.
 *
 * Đổi địa chỉ bằng `history.pushState`, **không** bằng `router.push`: cái sau
 * là một lượt điều hướng thật, nó tháo cả trang bản đồ và dựng lại trang chi
 * tiết — tức mất khung nhìn, mất tập pin, mất chỗ đang đứng trong danh sách.
 * Đúng thứ lát này sinh ra để giữ.
 *
 * ══ Nội dung gọi từ trình duyệt, và đó là chỗ KHÁC trang đầy đủ ══
 *
 * Thẻ này gọi `GET /api/v2/screens/{slug}` khi mở. Nó không cần có trong HTML
 * máy chủ: công cụ tìm kiếm không bấm vào thẻ, nó đi thẳng vào
 * `/explore/{slug}` — đường đó không đổi một dòng nào ở lát này.
 *
 * Nội dung rút gọn so với trang đầy đủ: không thư viện ảnh, không tab, không
 * khối vị trí, không "Inventory tương tự". Giữ lại thứ trả lời được câu *có
 * nên vào kế hoạch không* — ảnh, tên, bốn ô số liệu, khối giá và dự báo, các
 * hàng thông tin. Muốn đủ thì có nút sang trang đầy đủ.
 */

/** Ô số liệu — cùng bốn ô và cùng bộ class với trang đầy đủ, cố ý. */
function O({ nhan, gt }: { nhan: string; gt?: string | null }) {
    return (
        <div className="stat-box">
            <div className="stat-n">{gt || '—'}</div>
            <div className="stat-l">{nhan}</div>
        </div>
    );
}

function Hang({ nhan, gt }: { nhan: string; gt?: string | number | null }) {
    if (gt === null || gt === undefined || gt === '') return null;

    return (
        <div className="ex-ct-hang">
            <span className="ex-ct-hang-l">{nhan}</span>
            <span className="ex-ct-hang-v">{gt}</span>
        </div>
    );
}

function tien(so: number, donVi: string): string {
    return donVi === 'VND'
        ? `${new Intl.NumberFormat('vi-VN').format(so)} ₫`
        : `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(so)} ${donVi}`;
}

export function TheChiTiet({ slug, dong }: { slug: string; dong: () => void }) {
    const [man, setMan] = useState<ScreenDetail | null>(null);
    const [loi, setLoi] = useState<string | null>(null);

    /**
     * Cùng ngăn kéo hai bậc với panel danh sách và cột lọc (#145).
     *
     * Dưới 1024px thẻ này là ngăn kéo thứ hai, nằm **trên** panel danh sách
     * (z-index 320 so với 300) — ở bề ngang 390px không có chỗ cho hai cột
     * cạnh nhau, nên chi tiết che danh sách và Back đưa về.
     */
    const { refNgan, rong, tay } = dungKeoSheet<HTMLElement>({
        mo: true,
        dong,
        nhan: 'chi tiết',
    });

    /**
     * Esc đóng thẻ.
     *
     * Đóng ở đây nghĩa là lùi lịch sử, nên Esc và nút × và nút Back của
     * trình duyệt đều đi qua một đường — ba lối ra mà chỉ một luật.
     */
    useEffect(() => {
        const phim = (e: KeyboardEvent) => {
            if (e.key === 'Escape') dong();
        };

        document.addEventListener('keydown', phim);

        return () => document.removeEventListener('keydown', phim);
    }, [dong]);

    /**
     * Mỗi lần đổi slug là một lượt gọi mới, và lượt cũ bị bỏ.
     *
     * Bấm nhanh hai pin thì hai lời gọi chồng nhau, và cái cũ về sau sẽ vẽ
     * đè lên cái mới — cùng cái bẫy `seqRef` chặn ở `useBanDoPin`, chỉ khác
     * là ở đây `AbortController` làm được việc đó rẻ hơn.
     */
    useEffect(() => {
        const huy = new AbortController();

        setMan(null);
        setLoi(null);

        void (async () => {
            try {
                const res = await fetch(`/api/v2/screens/${encodeURIComponent(slug)}`, {
                    headers: { Accept: 'application/json' },
                    signal: huy.signal,
                });

                if (!res.ok) {
                    setLoi(res.status === 404 ? 'Không tìm thấy vị trí này.' : 'Không tải được chi tiết.');

                    return;
                }

                const body = (await res.json()) as { data: ScreenDetail };
                setMan(body.data);
            } catch (e) {
                if ((e as Error)?.name === 'AbortError') return;
                setLoi('Không tải được chi tiết.');
            }
        })();

        return () => huy.abort();
    }, [slug]);

    const lop = rong ? 'ex-ct sheet-rong' : 'ex-ct';

    const anh = man?.photo_urls?.[0] ?? null;
    const thanhPho = man?.site?.city ?? man?.location?.city ?? null;
    const spec = man?.specs;

    return (
        <aside ref={refNgan} className={lop} aria-label="Chi tiết vị trí">
            <div className="ex-ct-tay" {...tay}>
                <div className="mp-drag-bar" />
            </div>

            <button type="button" className="ex-ct-dong" onClick={dong} aria-label="Đóng chi tiết">
                ×
            </button>

            <div className="ex-ct-cuon">
                {loi ? <div className="ex-ct-loi">{loi}</div> : null}

                {!man && !loi ? <div className="ex-ct-cho">Đang tải…</div> : null}

                {man ? (
                    <>
                        {anh ? (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img className="ex-ct-anh" src={anh} alt={man.name ?? ''} />
                        ) : null}

                        <div className="ex-ct-than">
                            <h2 className="ex-ct-ten">{man.name}</h2>

                            <div className="ex-ct-meta">
                                {[thanhPho, man.owner?.name].filter(Boolean).join(' · ')}
                            </div>

                            {/*
                              Hành động, theo thứ tự người mua làm tiếp: ghim
                              lại để so sánh → xem đủ → hỏi người bán.
                              Hành động thứ tư — đặt chỗ — nằm trong
                              `PanelGia` bên dưới, nơi nó đi kèm con số.
                              Plan vẽ bốn nút ngang; ở đây là ba, vì nút thứ
                              tư của bản vẽ là "yêu cầu báo giá" cho MỘT màn
                              hình, mà `/explore/yeu-cau-bao-gia` gửi theo
                              **danh sách đã khoanh** chứ không theo một
                              slug. Một nút dẫn tới trang không làm việc nó
                              hứa thì thà không có.
                            */}
                            <div className="ex-ct-nut">
                                <OTick
                                    muc={{
                                        slug: man.slug ?? slug,
                                        ten: man.name ?? slug,
                                        thanhPho: thanhPho ?? undefined,
                                        owner: man.owner?.name ?? undefined,
                                        quan: man.location?.district ?? undefined,
                                        loaiHinh: man.screen_type_label ?? undefined,
                                        gia: man.pricing?.io_rate?.amount ?? undefined,
                                        ky: man.pricing?.io_rate?.unit ?? undefined,
                                    }}
                                    nhan={`Thêm ${man.name ?? 'vị trí này'} vào danh sách`}
                                />

                                <Link href={`/explore/${man.slug ?? slug}`} className="btn btn-p btn-sm">
                                    Trang đầy đủ
                                </Link>

                                {man.owner?.slug ? (
                                    <Link href={`/owners/${man.owner.slug}`} className="btn btn-s btn-sm">
                                        Liên hệ owner
                                    </Link>
                                ) : null}
                            </div>

                            <div className="stats-row">
                                <O nhan="Site" gt={man.site?.name} />
                                <O nhan="Network" gt={man.network?.name} />
                                <O
                                    nhan="Kích thước"
                                    gt={
                                        man.size?.width_m && man.size?.height_m
                                            ? `${man.size.width_m}×${man.size.height_m}m`
                                            : null
                                    }
                                />
                                <O
                                    nhan="Floor CPM"
                                    gt={
                                        man.pricing?.floor_cpm?.amount
                                            ? tien(
                                                  man.pricing.floor_cpm.amount,
                                                  man.pricing.floor_cpm.currency ?? 'VND',
                                              )
                                            : null
                                    }
                                />
                            </div>

                            {/*
                              Khối giá và dự báo: dùng lại `PanelGia` của
                              trang đầy đủ, không viết bản thứ hai. Hai bản
                              tính tiền cho cùng một màn hình là hai con số
                              sẽ trôi khỏi nhau, và người mua thấy cả hai.
                            */}
                            <PanelGia screen={man} />

                            <div className="ex-ct-bang">
                                <Hang nhan="Địa chỉ" gt={man.site?.address} />
                                <Hang nhan="Tỉnh/Thành" gt={thanhPho} />
                                <Hang nhan="Loại điểm đặt" gt={spec?.venue_label} />
                                <Hang
                                    nhan="Độ phân giải"
                                    gt={
                                        spec?.width_px && spec?.height_px
                                            ? `${spec.width_px} × ${spec.height_px} px`
                                            : null
                                    }
                                />
                                <Hang
                                    nhan="Loại nội dung"
                                    gt={
                                        [spec?.allow_image ? 'Ảnh' : null, spec?.allow_video ? 'Video' : null]
                                            .filter(Boolean)
                                            .join(', ') || null
                                    }
                                />
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </aside>
    );
}
