import Link from 'next/link';

/**
 * Trang 404 — trả **mã 404 thật**, không phải 200 kèm chữ "không tìm thấy".
 *
 * Next tự đặt mã trạng thái khi trang được render qua `notFound()`, nên chỗ
 * này chỉ lo phần hiển thị.
 */
export default function NotFound() {
    return (
        <main className="w" style={{ padding: '96px 20px', textAlign: 'center' }}>
            <h1>Không tìm thấy trang</h1>
            <p style={{ color: 'var(--t3)', marginTop: 12 }}>
                Vị trí này có thể đã được gỡ khỏi sàn, hoặc đường dẫn không đúng.
            </p>
            <p style={{ marginTop: 24 }}>
                <Link className="btn btn-p" href="/explore">
                    Xem tất cả vị trí
                </Link>
            </p>
        </main>
    );
}
