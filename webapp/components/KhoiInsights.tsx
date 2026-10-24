import type { ScreenInsights } from '@/lib/api';
import { coNoiDung, coSoKhanGia } from '@/lib/so-khan-gia';

/**
 * Tab "Insights" của trang chi tiết.
 *
 * Dựng lại `frontpage/partials/screen-insights.blade.php` (315 dòng): hàng số
 * liệu, chân dung khán giả, hiệu suất theo thời gian, bối cảnh quanh điểm, và
 * ghi chú phương pháp.
 *
 * ══ Một khối của bản Blade KHÔNG dựng lại: dòng "Nguồn traffic: Data Engine" ══
 *
 * Bản Blade đọc `$screen->traffic_estimate` — accessor gọi HTTP sang một
 * tunnel nội bộ, và in kèm `confidence_score`, `estimation_method`, thời điểm
 * tính. `/api/v2/screens/{slug}` cố ý **không** gọi đường đó: nó là endpoint
 * công khai có giới hạn tần suất, và để nó chờ một dịch vụ khác là đổi một
 * trang danh mục thành một trang phụ thuộc tunnel còn sống.
 *
 * Hệ quả nhìn thấy được: số liệu ở đây là cột do media owner khai, và
 * `insights.source` nói đúng thế (`owner`). Badge "Đo" của bản Blade vẫn còn;
 * badge "DE" thì không có gì để hiện.
 *
 * ══ Server component ══
 *
 * Không `use client`: khối này không có tương tác nào. Thanh phần trăm là
 * `width` tính ở máy chủ, và đó là lý do công cụ tìm kiếm đọc được số liệu.
 */

const NGAY: { ma: string; nhan: string }[] = [
    { ma: 'mon', nhan: 'T2' },
    { ma: 'tue', nhan: 'T3' },
    { ma: 'wed', nhan: 'T4' },
    { ma: 'thu', nhan: 'T5' },
    { ma: 'fri', nhan: 'T6' },
    { ma: 'sat', nhan: 'T7' },
    { ma: 'sun', nhan: 'CN' },
];

const THU_NHAP: Record<string, string> = {
    low: 'Thu nhập thấp',
    mid: 'Thu nhập trung',
    high: 'Thu nhập cao',
};

const TUOI: { khoa: keyof NonNullable<NonNullable<ScreenInsights>['audience_profile']>; nhan: string }[] = [
    { khoa: 'age_18_24_pct', nhan: '18-24' },
    { khoa: 'age_25_34_pct', nhan: '25-34' },
    { khoa: 'age_35_44_pct', nhan: '35-44' },
    { khoa: 'age_45_plus_pct', nhan: '45+' },
];

const BUOI: { khoa: 'morning_pct' | 'afternoon_pct' | 'evening_pct'; nhan: string; gio: string; mau: string }[] = [
    { khoa: 'morning_pct', nhan: 'Sáng', gio: '6h-12h', mau: 'org' },
    { khoa: 'afternoon_pct', nhan: 'Chiều', gio: '12h-18h', mau: 'bl' },
    { khoa: 'evening_pct', nhan: 'Tối', gio: '18h-24h', mau: 'pur' },
];

function so(n: unknown): number {
    return typeof n === 'number' ? n : 0;
}

/**
 * Khối thay cho tab rỗng — và nó phải nói ĐƯỢC GÌ, không chỉ nói "không có".
 *
 * Người mua đứng trước một hồ sơ thiếu số khán giả có đúng hai đường: bỏ qua
 * vị trí, hoặc đòi chủ vị trí cung cấp số. Khối này nói rõ hệ quả (không tính
 * được CPM, nên không so sánh được vị trí này với vị trí khác) rồi đưa đường
 * thứ hai thành một cú bấm.
 *
 * Nút trỏ `/explore/yeu-cau-bao-gia` — cùng một đường đã có, không dựng luồng
 * mới: yêu cầu báo giá và yêu cầu số liệu đều là một tin nhắn gửi tới chủ vị
 * trí qua sàn.
 */
function KhongCoSoLieu() {
    return (
        <div className="ins-trong">
            <div className="ins-trong-h">Chủ vị trí chưa cung cấp số liệu khán giả</div>
            <p className="ins-trong-p">
                Không có lượt khách, reach hay impressions thì <strong>không tính được CPM</strong>,
                nên chưa đặt được vị trí này cạnh vị trí khác để so sánh. Số liệu ở khối này do
                media owner khai, không phải sàn đo.
            </p>
            <a href="/explore/yeu-cau-bao-gia" className="btn btn-p btn-sm">
                Yêu cầu số liệu khán giả
            </a>
        </div>
    );
}

/** Thanh phần trăm, kẹp ở 100 — một cột dữ liệu 180% không được vẽ tràn ra. */
function Thanh({ nhan, gio, pct, mau }: { nhan: string; gio?: string; pct: number; mau: string }) {
    return (
        <div className="ins-bar-row">
            <span className="ins-bar-l">
                {nhan} {gio ? <small>{gio}</small> : null}
            </span>
            <div className="ins-bar">
                <div
                    className={`ins-bar-fill ins-bar-fill--${mau}`}
                    style={{ width: `${Math.min(Math.max(pct, 0), 100)}%` }}
                />
            </div>
            <span className="ins-bar-v">{pct}%</span>
        </div>
    );
}

export function KhoiInsights({ insights }: { insights: ScreenInsights }) {
    // ══ Lát 2: trống thì NÓI trống ══
    //
    // Trước lát này chỗ đây là `if (!insights) return null`, và nó bỏ sót đúng
    // trường hợp phổ biến nhất: đối tượng `insights` CÓ nhưng mọi trường bên
    // trong null (đo trên production 24/10/2026). Khối đi qua phép chặn ấy rồi
    // render một `<div class="ins">` rỗng — tab mở ra không có gì.
    //
    // Và trả `null` cũng không phải câu trả lời đúng: ẩn khối làm người mua
    // tưởng sàn không có phần số liệu khán giả, trong khi sự thật là **chủ vị
    // trí chưa khai**. Hai điều đó dẫn tới hai hành động khác nhau — bỏ qua vị
    // trí, hay đòi chủ vị trí cung cấp số.
    //
    // `!insights` đứng riêng trước `coSoKhanGia` không phải để chạy hai lần:
    // `coSoKhanGia` nhận `unknown` nên nó trả `boolean` chứ không phải một
    // type predicate, và TypeScript không thu hẹp `insights` qua nó. Viết
    // tường minh thì mười hai chỗ đọc `insights.*` bên dưới hết lỗi
    // TS18047 mà không cần `!` ở đâu cả.
    if (!insights || !coSoKhanGia(insights)) {
        return <KhongCoSoLieu />;
    }

    // `coNoiDung` thay cho phép kiểm `object ? (` ở ba nhánh dưới: một
    // `audience_profile` toàn null vẫn là một đối tượng, nên nó từng dựng tiêu
    // đề khối "Chân dung khán giả" cùng những thanh phần trăm 0% — tức vẽ ra
    // một chân dung gồm 0% nam và 0% nữ.
    const khanGia = coNoiDung(insights.audience_profile) ? insights.audience_profile : null;
    const thoiGian = coNoiDung(insights.time_performance) ? insights.time_performance : null;
    const quanh = coNoiDung(insights.nearby_context) ? insights.nearby_context : null;

    const coTuoi = TUOI.some((t) => khanGia?.[t.khoa] != null);
    const coBuoi = BUOI.some((b) => thoiGian?.[b.khoa] != null);
    const lifestyle = Array.isArray(khanGia?.lifestyle_tags) ? khanGia.lifestyle_tags : [];
    const coTraffic = insights.daily_footfall || insights.monthly_reach || insights.daily_impressions_est;

    return (
        <div className="ins">
            {coTraffic || insights.placement_zone ? (
                <>
                    <div className="ins-stats">
                        {insights.daily_footfall ? (
                            <div className="ins-stat">
                                <div className="ins-stat-l">
                                    Lượt khách / ngày{' '}
                                    <span
                                        className="ins-stat-src"
                                        title="Media owner cung cấp từ đo thực tế"
                                    >
                                        Đo
                                    </span>
                                </div>
                                <div className="ins-stat-v">
                                    {new Intl.NumberFormat('vi-VN').format(insights.daily_footfall)}
                                </div>
                            </div>
                        ) : null}

                        {insights.monthly_reach ? (
                            <div className="ins-stat">
                                <div className="ins-stat-l">
                                    Reach / tháng{' '}
                                    <span
                                        className="ins-stat-src"
                                        title="Media owner cung cấp từ đo thực tế"
                                    >
                                        Đo
                                    </span>
                                </div>
                                <div className="ins-stat-v">
                                    {new Intl.NumberFormat('vi-VN').format(insights.monthly_reach)}
                                </div>
                            </div>
                        ) : null}

                        {insights.daily_impressions_est ? (
                            <div className="ins-stat">
                                {/*
                                  "Ước tính", không phải "Impressions / ngày"
                                  như bản Blade. Con số là `daily_footfall`
                                  × 0,6 — một hệ số kinh nghiệm. Gọi nó là
                                  impressions là trình bày một phép nhân như
                                  một phép đo, và `impression_logs` thì chưa
                                  có bản ghi nào.
                                */}
                                <div className="ins-stat-l">Impressions / ngày (ước tính)</div>
                                <div className="ins-stat-v">
                                    {new Intl.NumberFormat('vi-VN').format(
                                        insights.daily_impressions_est,
                                    )}
                                </div>
                            </div>
                        ) : null}

                        {insights.placement_label ? (
                            <div className="ins-stat">
                                <div className="ins-stat-l">Vị trí trong venue</div>
                                <div className="ins-stat-v ins-stat-v-text">
                                    {insights.placement_label}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </>
            ) : null}

            {khanGia ? (
                <div className="ins-block">
                    <div className="ins-block-h">
                        <svg viewBox="0 0 24 24" fill="currentColor" className="ins-ic" aria-hidden="true">
                            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z" />
                        </svg>
                        Chân dung khán giả
                    </div>

                    {khanGia.male_pct != null || khanGia.female_pct != null ? (
                        <div className="ins-row">
                            <Thanh nhan="Nam" pct={so(khanGia.male_pct)} mau="bl" />
                            <Thanh nhan="Nữ" pct={so(khanGia.female_pct)} mau="pur" />
                        </div>
                    ) : null}

                    {coTuoi ? (
                        <div className="ins-row" style={{ marginTop: 14 }}>
                            <div className="ins-row-h">Độ tuổi</div>
                            {TUOI.map((t) => (
                                <Thanh key={t.khoa} nhan={t.nhan} pct={so(khanGia[t.khoa])} mau="grn" />
                            ))}
                        </div>
                    ) : null}

                    {khanGia.income_tier || lifestyle.length ? (
                        <div className="ins-pills" style={{ marginTop: 14 }}>
                            {khanGia.income_tier ? (
                                <span className={`ins-pill ins-pill--${khanGia.income_tier}`}>
                                    {THU_NHAP[khanGia.income_tier] ?? khanGia.income_tier}
                                </span>
                            ) : null}

                            {lifestyle.map((tag) => (
                                <span className="ins-pill ins-pill--neutral" key={tag}>
                                    {tag}
                                </span>
                            ))}
                        </div>
                    ) : null}

                    {khanGia.source_note ? (
                        <div className="ins-source">Nguồn: {khanGia.source_note}</div>
                    ) : null}
                </div>
            ) : null}

            {thoiGian ? (
                <div className="ins-block">
                    <div className="ins-block-h">
                        <svg viewBox="0 0 24 24" fill="currentColor" className="ins-ic" aria-hidden="true">
                            <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z" />
                        </svg>
                        Hiệu suất theo thời gian
                    </div>

                    {thoiGian.best_day ? (
                        <>
                            <div className="ins-days">
                                {NGAY.map((n) => (
                                    <div
                                        key={n.ma}
                                        className={n.ma === thoiGian.best_day ? 'ins-day is-best' : 'ins-day'}
                                    >
                                        {n.nhan}
                                    </div>
                                ))}
                            </div>
                            <div className="ins-day-note">
                                Ngày đẹp nhất:{' '}
                                <strong>{NGAY.find((n) => n.ma === thoiGian.best_day)?.nhan ?? ''}</strong>
                            </div>
                        </>
                    ) : null}

                    {coBuoi ? (
                        <div className="ins-row" style={{ marginTop: 16 }}>
                            <div className="ins-row-h">Phân bổ traffic theo buổi</div>
                            {BUOI.map((b) => (
                                <Thanh
                                    key={b.khoa}
                                    nhan={b.nhan}
                                    gio={b.gio}
                                    pct={so(thoiGian[b.khoa])}
                                    mau={b.mau}
                                />
                            ))}
                        </div>
                    ) : null}

                    {thoiGian.rationale ? (
                        <div className="ins-source">↳ {thoiGian.rationale}</div>
                    ) : null}
                </div>
            ) : null}

            {quanh ? (
                <div className="ins-block">
                    <div className="ins-block-h">
                        <svg viewBox="0 0 24 24" fill="currentColor" className="ins-ic" aria-hidden="true">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" />
                        </svg>
                        Bối cảnh xung quanh
                    </div>

                    {quanh.highlights ? <div className="ins-highlight">{quanh.highlights}</div> : null}

                    {Array.isArray(quanh.brands) && quanh.brands.length ? (
                        <div className="ins-tags-group">
                            <div className="ins-tags-l">Brand lân cận</div>
                            <div className="ins-tags">
                                {quanh.brands.map((b) => (
                                    <span className="ins-tag ins-tag--bl" key={b}>
                                        {b}
                                    </span>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    {Array.isArray(quanh.landmarks) && quanh.landmarks.length ? (
                        <div className="ins-tags-group">
                            <div className="ins-tags-l">Địa danh / landmark</div>
                            <div className="ins-tags">
                                {quanh.landmarks.map((l) => (
                                    <span className="ins-tag ins-tag--grn" key={l}>
                                        {l}
                                    </span>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {insights.methodology_note ? (
                <div className="ins-source ins-source-foot">
                    <strong>Nguồn dữ liệu:</strong> {insights.methodology_note}
                </div>
            ) : null}

            {/*
              Nói ra nguồn của cả khối, một lần.
              Bản Blade in một dòng tương tự cho Data Engine; ở đây nguồn là
              media owner, và một trang sắp được cơ quan quản lý đọc thì phải
              nói rõ con số đến từ đâu.
            */}
            <div className="ins-source">
                Số liệu trong mục này do media owner khai báo, OOHX chưa đo độc lập.
            </div>
        </div>
    );
}
