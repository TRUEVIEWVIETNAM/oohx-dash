'use client';

import Link from 'next/link';
import { mauPin, soVN, type CumSite } from '@/lib/ban-do';
import { OTick } from '@/components/OTick';

/**
 * Popup của bản đồ — dùng chung cho `/map` và cột bản đồ của `/explore`.
 *
 * Bản Blade dựng một `div#popup` cố định ngoài bản đồ rồi điền bằng JS, chứ
 * không dùng popup của Leaflet — vì nó có bố cục riêng (ảnh, giá, danh sách
 * màn hình trong site) và phải đứng yên khi bản đồ di chuyển. Giữ nguyên cách
 * đó, nhưng nội dung là JSX nên không có chuỗi HTML nào phải tự thoát.
 *
 * Tách khỏi `ScreenMap` ngày 19/10/2026 (lát 3): bản đồ cạnh danh sách cần
 * đúng cái popup này, và chép sang là tạo hai popup sẽ trôi khỏi nhau.
 *
 * `.map-popup` định vị **tuyệt đối** theo khung cha, nên nơi đặt nó phải là
 * một phần tử `position: relative` — ở `/map` là `.map-area`, ở `/explore` là
 * `.ex-map-nen`.
 */
export function PopupBanDo({
    cum,
    dong,
    khiMoChiTiet,
}: {
    cum: CumSite;
    dong: () => void;

    /**
     * Mở thẻ chi tiết cạnh danh sách thay vì rời trang (lát 5, 22/10/2026).
     *
     * `undefined` ở `/map`, nơi không có thẻ nào để mở — ở đó hai liên kết
     * dưới đây vẫn là liên kết thật và dẫn sang trang đầy đủ. Vì thế chúng
     * giữ nguyên `href`: chặn cú bấm là một lớp phủ lên hành vi gốc, không
     * phải thay nó, nên bấm giữa hay Ctrl+bấm vẫn mở tab mới như mọi liên
     * kết khác trên sàn.
     */
    khiMoChiTiet?: (slug: string) => void;
}) {
    const gia = giaCum(cum);
    const dau = cum.manHinh[0];

    const bam = (slug: string | undefined) => (e: React.MouseEvent) => {
        if (!khiMoChiTiet || !slug) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

        e.preventDefault();
        khiMoChiTiet(slug);
    };

    return (
        <div className="map-popup">
            <button type="button" className="mpop-close" onClick={dong} aria-label="Đóng">
                ×
            </button>

            <div className="mpop-img">
                {dau?.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={dau.photo_url} alt={cum.tenSite} />
                ) : null}
            </div>

            <div className="mpop-body">
                <div className="mpop-bc">
                    {[cum.city, cum.networkName].filter(Boolean).join(' · ')}
                </div>

                <div className="mpop-name">{cum.tenSite}</div>

                {cum.address ? <div className="mpop-loc">{cum.address}</div> : null}

                {gia ? (
                    <div className="mpop-price">
                        {gia}
                        <span className="mpop-price-unit">/{dau?.price?.unit ?? 'tháng'}</span>
                    </div>
                ) : (
                    <div className="mpop-price mpop-price-quote">Liên hệ báo giá</div>
                )}

                {cum.manHinh.length > 1 ? (
                    <div className="mpop-list">
                        <div className="mpop-list-title">{cum.manHinh.length} màn hình tại đây</div>
                        <div className="mpop-list-body">
                            {cum.manHinh.slice(0, 5).map((p) => (
                                <Link key={p.slug} href={`/explore/${p.slug}`} className="mpop-product" onClick={bam(p.slug)}>
                                    {p.name}
                                </Link>
                            ))}
                        </div>
                    </div>
                ) : null}

                <div className="mpop-actions">
                    {/*
                      Ô tick cho **màn hình đầu** của cụm, không cho cả cụm:
                      danh sách rút gọn đếm theo màn hình, và một toà nhà có
                      năm màn hình thì người mua chọn cái nào là một quyết
                      định của họ. Cụm nhiều màn hình có danh sách ngay bên
                      trên để bấm sang từng cái.
                    */}
                    {dau?.slug ? (
                        <OTick
                            muc={{
                                slug: dau.slug,
                                ten: dau.name ?? dau.slug,
                                thanhPho: cum.city ?? undefined,
                                owner: cum.ownerName ?? undefined,
                            }}
                            nhan={`Thêm ${dau.name ?? 'vị trí này'} vào danh sách`}
                        />
                    ) : null}

                    <Link href={`/explore/${dau?.slug ?? ''}`} className="btn btn-p btn-sm" onClick={bam(dau?.slug)}>
                        Xem chi tiết
                    </Link>
                </div>
            </div>

            <div className="mpop-foot">
                <span
                    className="oohx-dot"
                    style={{ background: `var(--${mauPin(cum.venueType) === 'bl' ? 'bl' : mauPin(cum.venueType)})` }}
                />
                {cum.ownerName}
            </div>
        </div>
    );
}

/** Giá của màn hình đầu trong cụm, hoặc `null` khi cụm chưa có giá nào. */
export function giaCum(cum: CumSite): string | null {
    const p = cum.manHinh[0]?.price;

    if (!p?.amount) return null;

    const tien =
        (p.currency ?? 'VND') === 'VND'
            ? `${soVN(p.amount)} ₫`
            : `${soVN(p.amount)} ${p.currency}`;

    return p.unit ? `${tien}/${p.unit}` : tien;
}
