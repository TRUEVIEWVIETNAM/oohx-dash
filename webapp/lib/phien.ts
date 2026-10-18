'use client';

import { useEffect, useState } from 'react';
import type { components } from '@api-types';

export type Me = components['schemas']['Me'];

/**
 * Trạng thái phiên của người xem, dùng chung cho thanh đầu và menu trượt.
 *
 * ══ Vì sao tách ra khỏi `AuthState` ══
 *
 * Từ 18/10/2026 có HAI chỗ cần biết người xem đã đăng nhập chưa: `AuthState`
 * trong thanh đầu, và chân menu trượt trên điện thoại. Mỗi chỗ tự `fetch` là
 * hai lời gọi `/api/v2/me` cho một câu trả lời, và hai chỗ có thể hiện hai
 * trạng thái khác nhau trong khoảnh khắc giữa hai phản hồi.
 *
 * ══ Một lời gọi cho cả trang ══
 *
 * `dangGoi` giữ lại chính Promise đang bay, nên component thứ hai gắn vào
 * sẽ chờ cùng lời gọi đó thay vì mở lời gọi mới. `daXong` giữ kết quả để
 * component gắn sau nữa không phải chờ gì.
 *
 * Bộ nhớ này sống theo vòng đời trang, không qua `localStorage`: một phiên
 * cache trên đĩa là một phiên sẽ sai sau khi người dùng đăng xuất ở tab
 * khác.
 *
 * ══ Không đọc ở máy chủ ══
 *
 * Trang công khai cache 60 giây. Render trạng thái đăng nhập ở máy chủ nghĩa
 * là bản cache phục vụ tên và email của người A cho người B — xem docblock
 * của `SiteHeader`.
 */

let daXong: { me: Me | null } | null = null;
let dangGoi: Promise<Me | null> | null = null;

async function doc(): Promise<Me | null> {
    try {
        const response = await fetch('/api/v2/me', {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return null; // 401 = khách

        const body = (await response.json()) as { data: Me };

        return body.data;
    } catch {
        // Mạng lỗi thì coi như khách. Thanh điều hướng không phải chỗ báo lỗi
        // mạng.
        return null;
    }
}

export function layPhien(): Promise<Me | null> {
    if (daXong) return Promise.resolve(daXong.me);
    if (dangGoi) return dangGoi;

    dangGoi = doc().then((me) => {
        daXong = { me };
        dangGoi = null;

        return me;
    });

    return dangGoi;
}

export function usePhien(): Me | null {
    const [me, setMe] = useState<Me | null>(daXong ? daXong.me : null);

    useEffect(() => {
        let huy = false;

        layPhien().then((ketQua) => {
            if (!huy) setMe(ketQua);
        });

        return () => {
            huy = true;
        };
    }, []);

    return me;
}
