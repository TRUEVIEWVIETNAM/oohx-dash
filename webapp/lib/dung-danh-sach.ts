'use client';

import { useSyncExternalStore } from 'react';
import { dangKy, doc, docTrenMayChu, type MucDaChon } from './danh-sach-rut-gon';

/**
 * Danh sách rút gọn, đọc được từ bất kỳ component nào.
 *
 * `useSyncExternalStore` chứ không phải `useState` + `useEffect`: danh sách
 * sống ngoài React (trong `localStorage`), và có **nhiều** component cùng
 * đọc nó — ô tick trên từng thẻ, ô tick trong popup bản đồ, thanh đáy. Với
 * state cục bộ thì tick ở popup không làm ô tick trên thẻ đổi theo, và lỗi
 * đó chỉ hiện ra khi người dùng mở cả hai cùng lúc.
 *
 * Tham số thứ ba là bản chụp phía máy chủ. Thiếu nó thì render ở máy chủ ném
 * lỗi; trả một mảng mới mỗi lần thì React render vô tận — nên nó là **một**
 * mảng rỗng dùng chung, xem `docTrenMayChu()`.
 */
export function dungDanhSach(): MucDaChon[] {
    return useSyncExternalStore(dangKy, doc, docTrenMayChu);
}
