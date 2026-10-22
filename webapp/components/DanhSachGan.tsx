'use client';

import { useEffect, useState } from 'react';
import type { ScreenSummary } from '@/lib/api';
import { ScreenCard } from '@/components/ScreenCard';
import { truyVanPin } from '@/lib/pin-api';

/**
 * Danh sách màn hình **gần người dùng nhất**, thay cho danh sách máy chủ dựng
 * khi người dùng đã cho phép định vị (22/10/2026).
 *
 * ══ Lỗi nó sửa ══
 *
 * Sau lát định vị, bản đồ bay tới chỗ người dùng còn danh sách bên cạnh vẫn
 * là toàn kho. Đo trên production: bản đồ ở Hà Nội với 13 vị trí quanh người
 * dùng, thẻ đầu tiên trong danh sách ở **Thái Nguyên**. Hai tập dữ liệu cạnh
 * nhau trên một màn hình, và người mua không có cách nào biết vì sao.
 *
 * ══ Toạ độ đi trong lời gọi API, KHÔNG trong URL trang ══
 *
 * Cách rẻ nhất để sắp lại danh sách là đẩy toạ độ vào URL
 * (`/explore?near_lat=…`) rồi để máy chủ dựng. **Không làm**: URL của một
 * trang công khai rơi vào log máy chủ, vào header `Referer` của mọi tài
 * nguyên bên thứ ba, và vào nút chia sẻ. Vị trí của một người không thuộc về
 * chỗ đó.
 *
 * Nên trang do máy chủ dựng vẫn là toàn kho — cũng là thứ giữ cho nó được
 * index — và phần sắp theo khoảng cách do trình duyệt gọi sau khi người dùng
 * cho phép.
 *
 * ══ Hỏng thì trả lại danh sách cũ ══
 *
 * `khiHong` báo lên cha để cha dựng lại `children` (danh sách máy chủ). Một
 * danh sách trống vì lời gọi hỏng là mất nội dung; danh sách toàn kho thì chỉ
 * là không sắp theo khoảng cách.
 */
export function DanhSachGan({
    lat,
    lng,
    query,
    soMoi,
    khiHong,
}: {
    lat: number;
    lng: number;

    /** Bộ lọc đang bật — danh sách gần phải tôn trọng đúng bộ lọc đó. */
    query?: Record<string, string | undefined>;

    /** Báo tổng số lên cha để thanh kết quả nói đúng con số. */
    soMoi?: (n: number | null) => void;

    khiHong?: () => void;
}) {
    const [ds, setDs] = useState<ScreenSummary[] | null>(null);

    const khoaLoc = truyVanPin(query);

    useEffect(() => {
        const huy = new AbortController();

        void (async () => {
            try {
                const p = new URLSearchParams(khoaLoc);

                p.set('sort', 'distance');
                p.set('near_lat', String(lat));
                p.set('near_lng', String(lng));
                p.set('per_page', '24');

                const res = await fetch(`/api/v2/screens?${p.toString()}`, {
                    headers: { Accept: 'application/json' },
                    signal: huy.signal,
                });

                if (!res.ok) {
                    khiHong?.();

                    return;
                }

                const body = (await res.json()) as {
                    data: ScreenSummary[];
                    meta?: { total?: number };
                };

                setDs(body.data);
                soMoi?.(body.meta?.total ?? body.data.length);
            } catch (e) {
                if ((e as Error)?.name === 'AbortError') return;

                khiHong?.();
            }
        })();

        return () => huy.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lat, lng, khoaLoc]);

    if (!ds) return null;

    if (ds.length === 0) {
        return (
            <div className="ex-gan-trong">
                Không có vị trí nào khớp bộ lọc. Bỏ bớt một chiều lọc để thấy thêm.
            </div>
        );
    }

    return (
        <>
            {ds.map((s) => (
                <ScreenCard key={s.slug} screen={s} choPhepChon />
            ))}
        </>
    );
}
