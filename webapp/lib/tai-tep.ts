'use client';

/**
 * Đẩy một chuỗi xuống máy người dùng thành tệp (lát 7).
 *
 * Tách khỏi component vì nó là phần **không** test được bằng `node --test`:
 * `URL.createObjectURL` và một cú click giả trên `<a download>` cần DOM thật.
 * Phần test được — dựng nội dung CSV — nằm ở `tep-csv.ts` và có chốt riêng.
 *
 * `revokeObjectURL` phải gọi, nhưng **không** gọi ngay: Safari đọc blob sau
 * khi click trả về, nên thu hồi tức thì cho ra một tệp rỗng. Hoãn một nhịp
 * là đủ, và một URL sống thêm vài giây không giữ bộ nhớ đáng kể.
 */
export function taiTep(noiDung: string, ten: string, kieu = 'text/csv;charset=utf-8'): void {
    const blob = new Blob([noiDung], { type: kieu });
    const url = URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = url;
    a.download = ten;

    // Phải nằm trong tài liệu thì Firefox mới kích hoạt cú click.
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();

    window.setTimeout(() => URL.revokeObjectURL(url), 2000);
}
