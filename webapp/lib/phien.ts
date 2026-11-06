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

/**
 * Những `usePhien()` đang gắn, để một thay đổi tới được mọi chỗ cùng lúc.
 *
 * ══ Vì sao cần, từ 06/11/2026 ══
 *
 * Trước đây phiên chỉ được **đọc** một lần rồi đứng yên, nên không chỗ nào
 * phải biết nó đổi. Nút "Thêm vào kế hoạch" làm `cart_count` tăng, và badge
 * giỏ ở thanh đầu đọc đúng con số ấy. Không có bộ này thì panel báo "đã thêm"
 * trong khi badge vẫn số cũ cho tới lần tải trang sau — hai chỗ trên cùng một
 * màn hình nói hai điều khác nhau về cùng một giỏ.
 *
 * Dùng `Set` chứ không một biến: thanh đầu và chân menu trượt cùng gắn
 * `usePhien()`, và cả hai đều phải nghe.
 */
const nguoiNghe = new Set<(me: Me | null) => void>();

function bao(me: Me | null): void {
    daXong = { me };
    nguoiNghe.forEach((nghe) => nghe(me));
}

/**
 * Ghi lại số món trong giỏ sau một lượt thêm, và báo cho mọi chỗ đang hiện nó.
 *
 * Nhận **con số máy chủ trả về** (`summary.item_count`), không phải số cũ
 * cộng một: hai tab cùng mở, hay một món đã có sẵn trong giỏ được
 * `updateOrCreate` gộp vào, đều làm phép cộng ở client ra số sai. Cùng lý do
 * tiền không được client tính.
 *
 * Không làm gì khi chưa biết phiên: chưa đăng nhập thì không có badge nào để
 * sửa, và dựng một `Me` giả ở đây là tạo ra một người dùng không có thật.
 */
export function datSoGio(soMon: number): void {
    if (!daXong?.me) return;

    bao({ ...daXong.me, cart_count: soMon });
}

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
        bao(me);
        dangGoi = null;

        return me;
    });

    return dangGoi;
}

export function usePhien(): Me | null {
    const [me, setMe] = useState<Me | null>(daXong ? daXong.me : null);

    useEffect(() => {
        let huy = false;

        const nghe = (ketQua: Me | null) => {
            if (!huy) setMe(ketQua);
        };

        // Đăng ký TRƯỚC khi gọi: nếu một component khác đã khởi lời gọi và nó
        // về đúng giữa hai dòng này, bản đăng ký muộn sẽ bỏ lỡ thông báo đó.
        // `layPhien()` bên dưới vẫn trả đúng kết quả nên không mất dữ liệu,
        // nhưng thứ tự này cũng đúng cho mọi `datSoGio()` xen vào sau.
        nguoiNghe.add(nghe);

        layPhien().then(nghe);

        return () => {
            huy = true;
            nguoiNghe.delete(nghe);
        };
    }, []);

    return me;
}
