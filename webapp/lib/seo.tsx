import type { Metadata } from 'next';
import { PUBLIC_ORIGIN } from './api';

/**
 * Dựng thẻ SEO **khớp đúng** bộ thẻ trang Blade đang phát.
 *
 * ══ Vì sao phải khớp, không phải "tương đương" ══
 *
 * Điều kiện hoàn thành giai đoạn 6 là "SEO không tụt", và
 * `tests/Feature/Frontpage/SeoBaselineTest.php` biến câu đó thành phép kiểm
 * chạy được trên bản Blade. Bản Next phải giữ, cho mỗi đường dẫn đã chuyển:
 *
 * - `<link rel="canonical">` trỏ đúng chính nó;
 * - `<meta name="description">` **không rỗng**;
 * - `og:title`, `og:description`, `og:image`, `og:url`, `og:type`,
 *   `og:site_name`, `og:locale`;
 * - `twitter:card` = `summary_large_image`, kèm title/description/image.
 *
 * Danh sách trên lấy từ `resources/views/frontpage/partials/seo-meta.blade.php`
 * — chép thiếu một thẻ là "tụt" theo đúng nghĩa lộ trình nói, và không có gì
 * báo cho tới khi ai đó mở công cụ kiểm SEO.
 *
 * ══ Mô tả không bao giờ rỗng ══
 *
 * `SeoBaselineTest::test_mo_ta_khong_bao_gio_rong` canh điều này. Nên hàm dưới
 * đây **luôn** có mô tả mặc định, và cắt ở 160 ký tự như bản Blade
 * (`Str::limit`). Cắt ở chỗ khác thì hai bản cho hai độ dài khác nhau cho cùng
 * một trang.
 */

const SITE_NAME = 'OOHX';

const DEFAULT_DESCRIPTION =
    'OOHX - Marketplace quảng cáo ngoài trời OOH/DOOH hàng đầu Việt Nam. ' +
    'Tìm, so sánh và đặt booking billboard, LED, LCD trong vài phút.';

const DEFAULT_IMAGE = `${PUBLIC_ORIGIN}/images/og-default.jpg`;

/**
 * Cắt như `Str::limit($text, 160)` của Laravel: 160 ký tự rồi thêm `...`.
 *
 * Không dùng `slice(0, 160)` trần — bản Blade thêm dấu ba chấm, nên mô tả của
 * hai bản sẽ khác nhau ở đuôi và một phép so sẽ trượt vì lý do không đáng.
 */
function limit(text: string, length = 160): string {
    const clean = text.replace(/\s+/g, ' ').trim();

    return clean.length <= length ? clean : `${clean.slice(0, length)}...`;
}

export type SeoInput = {
    title: string;
    /** Bỏ trống thì dùng mô tả mặc định — không bao giờ để rỗng. */
    description?: string | null;
    /** Đường dẫn tương đối, ví dụ `/explore`. Canonical dựng từ nó. */
    path: string;
    image?: string | null;
    type?: 'website' | 'article' | 'product';
};

export function buildMetadata({
    title,
    description,
    path,
    image,
    type = 'website',
}: SeoInput): Metadata {
    const url = `${PUBLIC_ORIGIN}${path.startsWith('/') ? path : `/${path}`}`;
    const desc = limit(description?.trim() ? description : DEFAULT_DESCRIPTION);
    const img = image || DEFAULT_IMAGE;

    return {
        title,
        description: desc,

        // `alternates.canonical` là cách Next phát `<link rel="canonical">`.
        alternates: { canonical: url },

        openGraph: {
            title,
            description: desc,
            url,
            siteName: SITE_NAME,
            locale: 'vi_VN',
            images: [{ url: img }],

            // `type` KHÔNG khai ở đây — xem `OgType` dưới cùng file này.
        },

        twitter: {
            card: 'summary_large_image',
            title,
            description: desc,
            images: [img],
        },
    };
}

/**
 * Thẻ `og:type`, phát trực tiếp trong cây React.
 *
 * ══ Vì sao không đi qua Metadata API ══
 *
 * `OpenGraphType` của Next 16 **không có** `product`:
 * `'article' | 'book' | 'music.*' | 'profile' | 'website' | 'video.*'`. Mà
 * trang chi tiết màn hình ở bản Blade phát `product`, và đó là giá trị đúng —
 * mạng xã hội dựng thẻ xem trước khác nhau cho `product` và `website`. Hạ
 * xuống `website` cho vừa kiểu là làm SEO tụt đúng theo nghĩa lộ trình nói,
 * chỉ để TypeScript im.
 *
 * Đường thứ hai là `metadata.other`, và tôi đã thử: nó phát
 * `<meta name="og:type">`. **Sai** — Open Graph đòi `property=`, nên crawler bỏ
 * qua thẻ đó hoàn toàn. Một lỗi im lặng: trang vẫn hiện, thẻ vẫn ở trong
 * `<head>`, chỉ là không ai đọc nó.
 *
 * React 19 nâng `<meta>` ở bất kỳ đâu trong cây lên `<head>`, nên component
 * này cho đúng thẻ với đúng thuộc tính.
 */
export function OgType({ type }: { type: NonNullable<SeoInput['type']> }) {
    return <meta property="og:type" content={type} />;
}

/**
 * JSON-LD, nhúng bằng `<script type="application/ld+json">`.
 *
 * Trả về chuỗi đã `JSON.stringify` để component chỉ việc đặt vào
 * `dangerouslySetInnerHTML`. Không dùng `<script>{JSON.stringify(x)}</script>`:
 * React escape nội dung text node, nên dấu ngoặc kép thành `&quot;` và khối
 * JSON-LD không còn phân tích được — một lỗi im lặng, vì trang vẫn hiện bình
 * thường và chỉ công cụ đọc dữ liệu có cấu trúc mới thấy.
 *
 * `</script>` trong dữ liệu bị vô hiệu hoá: một tên màn hình do media owner
 * đặt đi thẳng vào đây, và chuỗi đó sẽ đóng thẻ script sớm.
 */
export function jsonLd(data: Record<string, unknown>): { __html: string } {
    return {
        __html: JSON.stringify(data).replace(/</g, '\\u003c'),
    };
}
