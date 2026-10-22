'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { BAN_KINH_KM, khungQuanh } from '@/lib/vi-tri';
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
    dem = false,
}: {
    trangThai: TrangThaiViTri;

    /** Có hỏi API đếm số vị trí quanh đó không (chỉ kiểu lưới). */
    dem?: boolean;
}) {
    const [so, setSo] = useState<number | null>(null);

    const lat = trangThai.pha === 'co' ? trangThai.lat : null;
    const lng = trangThai.pha === 'co' ? trangThai.lng : null;

    useEffect(() => {
        if (!dem || lat === null || lng === null) return;

        const huy = new AbortController();

        void (async () => {
            try {
                const k = khungQuanh(lat, lng);
                const p = new URLSearchParams({
                    north: String(k.north),
                    south: String(k.south),
                    east: String(k.east),
                    west: String(k.west),
                });

                const res = await fetch(`/api/v2/screens/map?${p.toString()}`, {
                    headers: { Accept: 'application/json' },
                    signal: huy.signal,
                });

                if (!res.ok) return;

                const body = (await res.json()) as { meta?: { total?: number } };

                setSo(body.meta?.total ?? 0);
            } catch {
                // Hỏng thì dòng này không hiện con số nào. Nó là phần thêm,
                // không phải nội dung của trang.
            }
        })();

        return () => huy.abort();
    }, [dem, lat, lng]);

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

    if (!dem) return null;

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
