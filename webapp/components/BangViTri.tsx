'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { BAN_KINH_KM, khungQuanh } from '@/lib/vi-tri';
import { layPin, truyVanPin } from '@/lib/pin-api';
import type { TrangThaiViTri } from '@/lib/dung-vi-tri';

/**
 * Một dòng nói trạng thái định vị — lát định vị, 22/10/2026.
 *
 * ══ Vì sao nó phải tồn tại ở kiểu LƯỚI ══
 *
 * Chủ dự án chốt xin quyền khi mở `/explore`, và lưới là kiểu xem mặc định.
 * Xin quyền rồi không làm gì thấy được là cách chắc chắn nhất để người dùng
 * bấm "Chặn" ở lần sau — và trình duyệt nhớ cú chặn đó vĩnh viễn.
 *
 * Nên ở kiểu lưới, dòng này trả lời bằng một con số thật: *quanh bạn có bao
 * nhiêu vị trí*, kèm đường sang bản đồ. Nó hỏi đúng một lần, qua chính
 * `GET /api/v2/screens/map` mà kiểu bản đồ dùng — không endpoint mới, không
 * Leaflet, không tile. Một lời gọi, một con số.
 *
 * Ở kiểu bản đồ thì bản đồ tự bay tới người dùng, nên dòng này chỉ còn phần
 * trạng thái (đang hỏi / bị chặn), không đếm lại.
 *
 * ══ Bị chặn thì nói một câu, và thôi ══
 *
 * Không nút "Cho phép" to đùng, không hộp thoại giải thích. Trình duyệt đã
 * nhớ quyết định đó và chỉ người dùng mới gỡ được trong cài đặt site; một
 * nút gọi lại `getCurrentPosition` ở đây sẽ im lặng không làm gì, và đó là
 * loại nút tệ nhất.
 */
export function BangViTri({
    trangThai,
    query,
    dem = false,
}: {
    trangThai: TrangThaiViTri;

    /**
     * Bộ lọc đang bật.
     *
     * Con số phải đếm ĐÚNG tập người dùng đang xem. Bản đầu bỏ qua bộ lọc,
     * nên với `?screen_type=lcd` dòng này nói "104 vị trí quanh bạn" trong
     * khi danh sách ngay dưới có 20 — hai con số cho hai câu hỏi khác nhau,
     * cách nhau một dòng, và không gì nói ra điều đó.
     */
    query?: Record<string, string | undefined>;

    /** Có hỏi API đếm số vị trí quanh đó không (chỉ kiểu lưới). */
    dem?: boolean;
}) {
    const [so, setSo] = useState<number | null>(null);

    /**
     * Lời gọi đếm hỏng.
     *
     * Đo ở máy: `next start` không rewrite `/api/v2`, nên lời gọi 404 và
     * dòng chữ **kẹt mãi** ở "Đang đếm vị trí quanh bạn…". Một dòng loading
     * không bao giờ kết thúc còn tệ hơn không có dòng nào: nó nói với người
     * dùng rằng trang vẫn đang làm việc gì đó.
     *
     * Hỏng thì dòng này biến mất. Lưới ngay dưới đã là toàn kho — không có
     * gì để sửa lời, và không có gì để xin lỗi.
     */
    const [hong, setHong] = useState(false);

    /**
     * Bộ lọc dưới dạng chuỗi, và deps của effect là chuỗi đó.
     *
     * `query` là một object mới ở mỗi lượt render của cha. Lấy thẳng nó làm
     * deps là đếm lại sau mỗi lượt render — cùng cái bẫy `truyVan` của
     * `useBanDoPin` đã chặn.
     */
    const khoaLoc = truyVanPin(query);

    const lat = trangThai.pha === 'co' ? trangThai.lat : null;
    const lng = trangThai.pha === 'co' ? trangThai.lng : null;

    useEffect(() => {
        if (!dem || lat === null || lng === null) return;

        const huy = new AbortController();

        setSo(null);
        setHong(false);

        void (async () => {
            try {
                const body = await layPin(khungQuanh(lat, lng), truyVanPin(query), huy.signal);

                if (!body) {
                    setHong(true);

                    return;
                }

                setSo(body.meta?.total ?? 0);
            } catch (e) {
                // Huỷ vì đổi bộ lọc thì không phải hỏng — lượt mới đang chạy.
                if ((e as Error)?.name !== 'AbortError') setHong(true);
            }
        })();

        return () => huy.abort();
    }, [dem, lat, lng, khoaLoc]);

    if (trangThai.pha === 'tat') return null;

    if (trangThai.pha === 'dang-hoi') {
        return <p className="ex-vt">Đang tìm vị trí của bạn…</p>;
    }

    if (trangThai.pha === 'tu-choi') {
        return (
            <p className="ex-vt ex-vt-mo">
                Chưa bật vị trí — đang hiện toàn bộ kho. Bật lại trong cài đặt trình duyệt nếu
                bạn muốn xem vị trí quanh mình.
            </p>
        );
    }

    if (trangThai.pha === 'loi') {
        return <p className="ex-vt ex-vt-mo">Không lấy được vị trí — đang hiện toàn bộ kho.</p>;
    }

    if (!dem || hong) return null;

    if (so === null) {
        return <p className="ex-vt">Đang đếm vị trí quanh bạn…</p>;
    }

    if (so === 0) {
        return (
            <p className="ex-vt ex-vt-mo">
                Chưa có vị trí nào trong {BAN_KINH_KM}km quanh bạn — đang hiện toàn bộ kho.
            </p>
        );
    }

    return (
        <p className="ex-vt">
            <strong>{new Intl.NumberFormat('vi-VN').format(so)}</strong> vị trí trong{' '}
            {BAN_KINH_KM}km quanh bạn.{' '}
            <Link href="/explore?view=map" className="ex-vt-nut">
                Xem trên bản đồ
            </Link>
        </p>
    );
}
