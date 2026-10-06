import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getPolicy, listPolicies, type PolicyPage } from '@/lib/api';
import { CONG_TY } from '@/lib/company';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';

/**
 * `/{slug}` — bốn trang chính sách bắt buộc của sàn TMĐT.
 *
 * ══ Văn bản KHÔNG nằm trong file này ══
 *
 * Phần thân tới từ `GET /api/v2/policies/{slug}`, và endpoint đó render đúng
 * partial Blade mà trang Laravel render (`config('policies.pages.*.body')`).
 * Nên văn bản pháp lý vẫn có **một nguồn** và **một bộ render**.
 *
 * Chép văn bản sang một component React là thứ phải không làm. Những văn bản
 * này được đóng dấu `version` vào từng bản ghi đồng ý của người dùng: khi có
 * tranh chấp, câu trả lời là "đây là đúng chữ họ đã bấm đồng ý". Hai bản trôi
 * khỏi nhau thì câu đó không còn chứng minh được gì — và đó là mất bằng chứng,
 * không phải lỗi hiển thị.
 *
 * `tests/Feature/Api/V2/PublicContentApiTest::test_than_van_ban_la_dung_phan_than_trang_blade_phat_ra`
 * canh đúng chuyện này ở phía API.
 *
 * ══ `dangerouslySetInnerHTML` ở đây là có cân nhắc ══
 *
 * Nội dung do chính repo này viết và đi qua git review — không có dữ liệu
 * người dùng trong đó. Nhưng "tin được hôm nay" không phải một bảo đảm, nên
 * phía Laravel có test canh phần thân không chứa `<script`, `<iframe`,
 * `javascript:`, `onerror=`, `onload=`. Nếu sau này ai đưa một biến người dùng
 * nhập vào partial thân, test đó đỏ trước khi nó chảy ra trình duyệt.
 *
 * Đường thay thế duy nhất là một bộ phân tích HTML ở client để lọc thẻ, và nó
 * giải quyết đúng vấn đề này tệ hơn: nó sẽ im lặng cắt bớt văn bản pháp lý.
 *
 * ══ Chỉ bốn slug, không phải mọi đường dẫn một cấp ══
 *
 * `[slug]` ở gốc app sẽ nuốt mọi `/{gì-đó}`. Nên `dynamicParams = false` cùng
 * `generateStaticParams()` lấy danh sách từ API: slug ngoài danh sách ra 404
 * thật, không ra một trang 200 rỗng. Đây là bản Next của ràng buộc
 * `->whereIn('slug', array_keys(config('policies.pages')))` trên route Blade —
 * cùng một luật, cùng một nguồn danh sách.
 *
 * ══ Khung trang dựng lại từ `<x-policy-shell>` ══
 *
 * Tiêu đề, khối phiên bản, ô cảnh báo bản nháp: đó là khung, không phải văn
 * bản. Endpoint cố ý **không** trả chúng trong `body_html` — hai bên dựng
 * khung từ `title` / `version` / `effective_from`, và nếu khung lệch nhau thì
 * đó là lỗi hiển thị thật, sửa được mà không chạm vào văn bản.
 */

export const dynamicParams = false;

export async function generateStaticParams() {
    const { data } = await listPolicies();

    return data.map((page) => ({ slug: page.slug }));
}

type Params = { slug: string };

export async function generateMetadata({
    params,
}: {
    params: Promise<Params>;
}): Promise<Metadata> {
    const { slug } = await params;
    const page = await getPolicy(slug);

    if (!page) {
        return buildMetadata({ title: 'Không tìm thấy trang | OOHX', path: `/${slug}` });
    }

    return buildMetadata({
        // Dấu gạch ngang dài và thứ tự giống bản Blade
        // (`@section('title', $page['title'] . ' – OOHX')`), không phải
        // `| OOHX` như các trang danh mục: tiêu đề đổi là một thay đổi thấy
        // được trong kết quả tìm kiếm, và nó không có lý do để đổi ở đây.
        title: `${page.title ?? 'Chính sách'} – OOHX`,
        description: moTa(page),
        path: `/${slug}`,
    });
}

export default async function PolicyPageRoute({ params }: { params: Promise<Params> }) {
    const { slug } = await params;
    const page = await getPolicy(slug);

    if (!page) {
        notFound();
    }

    return (
        <>
            <SiteHeader />
            <OgType type="article" />

            <main>
                <section className="pol">
                    <div className="w pol-w">
                        <nav className="pol-crumb" aria-label="Breadcrumb">
                            <Link href="/">Trang chủ</Link>
                            <span aria-hidden="true">/</span>
                            <span>{page.title}</span>
                        </nav>

                        <h1 className="pol-h1">{page.title}</h1>

                        <div className="pol-meta">
                            <span>Phiên bản {page.version}</span>
                            {page.effective_from ? (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>Hiệu lực từ {page.effective_from}</span>
                                </>
                            ) : null}
                        </div>

                        {/*
                            Chưa ban hành thì phải NÓI RA. Trình bày một bản
                            nháp như văn bản đã có hiệu lực là nói sai với người
                            đọc về thứ họ đang bị ràng buộc bởi — và
                            `is_effective` của endpoint tồn tại chính để chỗ
                            này không phải tự suy luận.
                        */}
                        {!page.is_effective ? (
                            <div className="pol-draft" role="note">
                                <strong>Văn bản đang hoàn thiện.</strong> Nội dung dưới đây là bản
                                nháp và chưa có hiệu lực áp dụng. Bản chính thức sẽ được công bố
                                tại đúng địa chỉ này. Trong thời gian chờ, mọi thắc mắc xin liên
                                hệ <a href={`mailto:${CONG_TY.email}`}>{CONG_TY.email}</a> hoặc
                                hotline {CONG_TY.hotline}.
                            </div>
                        ) : null}

                        <article
                            className="pol-body"
                            dangerouslySetInnerHTML={{ __html: page.body_html }}
                        />
                    </div>
                </section>
            </main>
        </>
    );
}

/**
 * Mô tả cho `<meta name="description">`.
 *
 * KHÔNG cắt từ `body_html`: đó là HTML, và đoạn đầu của một quy chế là một câu
 * dẫn nhập không nói gì về trang. Bản Blade không đặt `$seoDescription` cho
 * nhóm trang này nên nó nhận mô tả chung của cả site — đúng kiểu "mô tả không
 * rỗng" nhưng không giúp ai. Một câu dựng từ siêu dữ liệu thì nói đúng trang
 * này là trang gì.
 */
function moTa(page: PolicyPage): string {
    const trangThai = page.effective_from
        ? `Hiệu lực từ ${page.effective_from}`
        : 'Bản nháp, chưa có hiệu lực áp dụng';

    return `${page.title} của sàn OOHX. Phiên bản ${page.version ?? '—'}. ${trangThai}.`;
}
