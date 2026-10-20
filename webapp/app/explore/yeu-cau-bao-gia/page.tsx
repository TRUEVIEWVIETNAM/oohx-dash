import type { Metadata } from 'next';
import { getFilters } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { FormBaoGia } from '@/components/FormBaoGia';

/**
 * `/explore/yeu-cau-bao-gia` — gửi yêu cầu báo giá cho danh sách đã khoanh
 * (lát 6).
 *
 * ══ Vì sao đường này nằm dưới `/explore` ══
 *
 * Nó là bước cuối của một việc bắt đầu ở trang kho, và plan đã khai đúng
 * đường này. Hệ quả phải biết: route tĩnh thắng route động, nên một màn hình
 * có slug `yeu-cau-bao-gia` sẽ bị trang này che. Không có màn hình nào như
 * vậy, và slug do owner đặt theo tên thiết bị — nhưng nếu có, nó sẽ biến mất
 * khỏi sàn một cách im lặng.
 *
 * ══ Nội dung do trình duyệt dựng, và đó là chủ ý ══
 *
 * Danh sách rút gọn sống trong `localStorage`; máy chủ không biết người này
 * đã chọn gì. Nên trang chỉ render khung, còn `FormBaoGia` điền phần còn
 * lại. Khác `/explore` ở chỗ đó, và vì thế trang này **không cần** index:
 * một trang biểu mẫu rỗng trong kết quả tìm kiếm không giúp ai.
 */
export const metadata: Metadata = {
    ...buildMetadata({
        title: 'Yêu cầu báo giá | OOHX',
        description: 'Gửi yêu cầu báo giá cho danh sách vị trí đã chọn.',
        path: '/explore/yeu-cau-bao-gia',
    }),

    // Trang thao tác, không phải trang nội dung. Nội dung thật nằm trong
    // `localStorage` của từng người, nên công cụ tìm kiếm chỉ thấy một khung
    // rỗng — và một khung rỗng trong chỉ mục là nhiễu.
    robots: { index: false, follow: true },
};

export default async function TrangYeuCauBaoGia() {
    /*
     * Câu mở đầu nói một con số, nên con số đó phải tự đúng.
     *
     * Bản đầu tôi viết cứng "85 trong 104" — đúng vào hôm viết, sai dần từ
     * hôm sau, và không có gì nhắc ai sửa. `/api/v2/filters` đã đếm sẵn hai
     * nhóm này cho cột lọc; đọc lại chúng rẻ hơn nhiều so với một con số cũ
     * trên trang bán hàng.
     *
     * API hỏng thì bỏ cả câu, không in "0 trong 0".
     */
    const boLoc = await getFilters().catch(() => null);

    const chuaBaoGia = boLoc?.price_status?.unquoted ?? 0;
    const tong = chuaBaoGia + (boLoc?.price_status?.quoted ?? 0);

    return (
        <>
            <SiteHeader active="explore" />
            <OgType type="website" />

            <main>
                <div className="ex-hero">
                    <div className="w">
                        <h1 className="ex-title">Yêu cầu báo giá</h1>
                        {/*
                          Một biểu thức, không ba mảnh: React chèn
                          `<!-- -->` giữa các text node liền kề, nên
                          `{a} trong {b} vị trí` ra HTML là
                          `103<!-- --> trong <!-- -->104` — câu vẫn đọc đúng
                          trên màn hình nhưng không còn là một chuỗi liền để
                          phép kiểm tìm. Ghép bằng template string thì HTML
                          có đúng câu người đọc thấy.
                        */}
                        <p className="bg-dan">
                            {chuaBaoGia > 0 && tong > 0
                                ? `${chuaBaoGia} trong ${tong} vị trí trên sàn chưa công bố giá. Gửi danh sách đã chọn để từng media owner báo giá trực tiếp cho bạn.`
                                : 'Gửi danh sách đã chọn để từng media owner báo giá trực tiếp cho bạn.'}
                        </p>
                    </div>
                </div>

                <div className="w">
                    <FormBaoGia />
                </div>
            </main>
        </>
    );
}
