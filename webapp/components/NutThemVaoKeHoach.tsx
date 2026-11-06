'use client';

import Link from 'next/link';
import { useState } from 'react';
import { datSoGio, usePhien } from '@/lib/phien';
import { themVaoGio, type KetQuaThemGio, type ThanThemGio } from '@/lib/gui-len-laravel';

/**
 * Nút đặt chỗ của hai panel giá, đổi theo người đang xem.
 *
 * ══ Vì sao có component này — lỗi 06/11/2026 ══
 *
 * Ba nút ở hai trang (`PanelGia` của `/explore/{slug}`, hai nút của
 * `PanelGiaGoi` ở `/products/{slug}`) đều viết cứng nhãn "Đăng nhập để
 * booking" và một liên kết `/login`. Người đã đăng nhập — avatar hiện rõ ở
 * thanh đầu — vẫn bị bảo đi đăng nhập. Lý do lịch sử nằm trong docblock cũ
 * của cả hai tệp: *"Khu người mua chưa chuyển sang Next. Một nút
 * `POST /cart/add` vào đường chưa tồn tại tệ hơn một nút nói thật là nó cần
 * đăng nhập."*
 *
 * Câu đó nay sai: `POST /api/v2/cart/items` đã có, cùng origin, nhận thẳng
 * slug. Nhưng cái sai còn lại thì vẫn đúng — nên nút này giữ nguyên hành vi
 * cũ cho khách, và chỉ đổi cho người đã đăng nhập.
 *
 * ══ Trạng thái đăng nhập KHÔNG được render ở máy chủ ══
 *
 * Trang công khai cache 60 giây. `AuthState` đã ghi lại cái bẫy này: render
 * phần phụ thuộc người dùng vào HTML máy chủ nghĩa là bản cache phục vụ
 * trạng thái của người A cho người B. Ở đây hậu quả nhẹ hơn một cái tên rò
 * ra, nhưng vẫn là sai: người chưa đăng nhập nhận một nút bấm vào sẽ 401.
 *
 * Nên `usePhien()` trả `null` ở lượt render đầu — kể cả khi người xem **đã**
 * đăng nhập — và HTML máy chủ luôn là nhánh khách. Nhánh người mua thay vào
 * sau khi `/api/v2/me` trả lời. Chốt `webapp/test/khoi-giao-dien.mjs` ghim
 * đúng điều đó bằng một `khong`.
 *
 * ══ Vì sao không rẽ theo vai trò ở client ══
 *
 * Giỏ hàng cần cả `auth:sanctum` **và** middleware `buyer`, nên một người
 * đăng nhập bằng tài khoản không phải người mua sẽ nhận 403. Nút không đoán
 * trước điều đó: `Me` không trả vai trò, và dựng một phép kiểm quyền thứ hai
 * ở trình duyệt là đúng thứ CLAUDE.md §2 cấm ("không dựa vào việc giao diện
 * ẩn nút"). Thay vào đó 403 có câu nói riêng, khác hẳn câu mời đăng nhập.
 */

type Props = {
    /**
     * Thân request, tính **lúc bấm**.
     *
     * Là một hàm chứ không một object: ngày bắt đầu, kiểu bán và danh sách
     * màn hình đã chọn đều đổi khi người dùng thao tác, và một object dựng
     * lúc render là một ảnh chụp cũ.
     */
    than: () => ThanThemGio;

    /** `/login?next=…` của đúng trang đang đứng. */
    duongDangNhap: string;

    /** Nhãn nhánh khách. Nhánh người mua luôn là "Thêm vào kế hoạch". */
    nhanKhach?: string;

    /** Chặn bấm khi chưa đủ dữ liệu — nhánh "Chọn lẻ" chưa tick màn hình nào. */
    voHieu?: boolean;

    /** Lý do bị chặn, hiện thay cho nút. */
    lyDoVoHieu?: string;

    children?: React.ReactNode;
};

const KIEU_NUT: React.CSSProperties = {
    width: '100%',
    justifyContent: 'center',
    borderRadius: 12,
    height: 52,
    textDecoration: 'none',
    border: 'none',
    cursor: 'pointer',
};

export function NutThemVaoKeHoach({
    than,
    duongDangNhap,
    nhanKhach = 'Đăng nhập để booking',
    voHieu = false,
    lyDoVoHieu,
    children,
}: Props) {
    const me = usePhien();
    const [dangGui, setDangGui] = useState(false);
    const [ketQua, setKetQua] = useState<KetQuaThemGio | null>(null);

    async function bam() {
        // Chặn bấm đôi: `POST /api/v2/cart/items` là `updateOrCreate` theo
        // (giỏ, màn hình) nên lượt thứ hai không sinh dòng thừa — nhưng nó
        // vẫn là một lượt đi mạng, và hai phản hồi về lệch nhau sẽ ghi đè
        // trạng thái của nhau.
        if (dangGui) return;

        setDangGui(true);
        setKetQua(null);

        const kq = await themVaoGio(than());

        if (kq.ok) datSoGio(kq.soMon);

        setKetQua(kq);
        setDangGui(false);
    }

    // Nhánh khách — cũng là thứ máy chủ render.
    if (!me) {
        return (
            <Link href={duongDangNhap} className="btn btn-p btn-lg" style={KIEU_NUT}>
                {children}
                {nhanKhach}
            </Link>
        );
    }

    if (voHieu) {
        return (
            <button type="button" className="btn btn-p btn-lg" style={{ ...KIEU_NUT, opacity: 0.5, cursor: 'not-allowed' }} disabled>
                {lyDoVoHieu ?? 'Chưa chọn màn hình nào'}
            </button>
        );
    }

    return (
        <>
            <button
                type="button"
                className="btn btn-p btn-lg"
                style={dangGui ? { ...KIEU_NUT, opacity: 0.7, cursor: 'wait' } : KIEU_NUT}
                disabled={dangGui}
                onClick={bam}
            >
                {children}
                {dangGui ? 'Đang thêm…' : 'Thêm vào kế hoạch'}
            </button>

            {ketQua?.ok ? (
                <div style={{ marginTop: 10, fontSize: 13, color: 'var(--t2)', textAlign: 'center' }}>
                    <span style={{ color: '#248A3D', fontWeight: 700 }}>Đã thêm vào kế hoạch.</span>{' '}
                    {/*
                      `<a>` chứ không `next/link`: `/cart` do Laravel phục vụ
                      (khu người mua còn Blade), và `next/link` sẽ thử điều
                      hướng phía client rồi hiện 404 của Next cho một trang có
                      thật — đúng lý do `AuthState` đã ghi cho `/login`.
                    */}
                    <a href="/cart" style={{ color: 'var(--bl)', fontWeight: 700 }}>
                        Xem kế hoạch ({ketQua.soMon})
                    </a>
                </div>
            ) : null}

            {ketQua && !ketQua.ok ? (
                <div
                    role="alert"
                    style={{
                        marginTop: 10,
                        fontSize: 13,
                        color: '#B3261E',
                        background: 'rgba(179, 38, 30, 0.08)',
                        borderRadius: 10,
                        padding: '8px 10px',
                    }}
                >
                    {ketQua.loi}
                    {ketQua.ma === 'khach' ? (
                        <>
                            {' '}
                            <a href={duongDangNhap} style={{ color: 'var(--bl)', fontWeight: 700 }}>
                                Đăng nhập lại
                            </a>
                        </>
                    ) : null}
                </div>
            ) : null}
        </>
    );
}
