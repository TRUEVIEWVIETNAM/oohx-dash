'use client';

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { KhoiKetQua } from '@/components/KhoiKetQua';
import { BanDoKetQua } from '@/components/BanDoKetQua';
import { DaiTongQuan } from '@/components/DaiTongQuan';
import { TheChiTiet } from '@/components/TheChiTiet';
import { ThanhDanhSach } from '@/components/ThanhDanhSach';
import { BangViTri } from '@/components/BangViTri';
import { dungKeoSheet } from '@/lib/dung-keo-sheet';
import { dungViTri, type TrangThaiViTri } from '@/lib/dung-vi-tri';
import type { TomTat } from '@/lib/khung-nhin';

/**
 * Khung của `/explore` — **một** đường dẫn, hai kiểu xem (lát 2, 22/10/2026).
 *
 * ══ Vì sao hai trang gộp lại ══
 *
 * Trước hôm nay cùng một kho được nhìn qua hai trang: `/explore` (ba cột, lọc
 * 260 · kết quả 528 · bản đồ 340) và `/map` (bản đồ toàn khung + panel trái).
 * Hai bộ lọc, hai bản đồ, hai cách bày danh sách cho một kho — và hai chỗ đó
 * đã trôi khỏi nhau: `/map` có thanh tỉnh thành và gom theo site, `/explore`
 * có trạng thái giá và danh sách rút gọn. Không bên nào có đủ.
 *
 * Bố cục kiểu xem bản đồ lấy từ Google Maps, đo thật ở 1440×900: canvas trải
 * hết khung nhìn và nằm **dưới**, danh sách là thẻ nổi bên trái. Chi tiết và
 * lý do ở `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * ══ Kiểu xem là một URL, không phải một state ══
 *
 * `?view=map`. Hai liên kết thật, không phải hai nút JS: quay lại được bằng
 * nút Back, chia sẻ được, và chạy cả khi JavaScript chưa tải xong. Đó cũng là
 * cách Google Maps làm — bấm một kết quả thì địa chỉ đổi theo.
 *
 * Mặc định là **lưới** khi không có tham số. Hai lý do, và cả hai đều về
 * người đến từ công cụ tìm kiếm: kiểu lưới mang theo phân trang và chân trang
 * — những đường duy nhất để Google bò sang trang 2, 3 và các trang chính sách
 * — còn kiểu bản đồ thì tải Leaflet, tile và tới 500 pin ngay lần đầu, kể cả
 * trên 3G. Đổi mặc định là đổi đúng một dòng ở `page.tsx`.
 *
 * ══ Thẻ vẫn do máy chủ dựng, ở CẢ HAI kiểu xem ══
 *
 * Đây là hàng rào quan trọng nhất của việc gộp. `/map` cố ý không có danh
 * sách trong HTML máy chủ, vì hai trang cùng dữ liệu mà cùng được index là tự
 * tạo nội dung trùng. Gộp lại mà để danh sách chờ bản đồ hỏi pin xong mới
 * hiện thì trang danh mục nặng nhất của sàn biến mất khỏi Google — một cái
 * giá không thấy lúc xem thiết kế, và thấy sau vài tuần.
 *
 * Nên trang vẫn gọi `listScreens()` ở máy chủ và truyền thẻ xuống đây qua
 * `children`, ở cả hai kiểu xem. Bản đồ chỉ **đổi** danh sách khi người dùng
 * yêu cầu. `webapp/test/khoi-giao-dien.mjs` canh điều đó cho cả hai URL.
 *
 * ══ Rê chuột bắt bằng uỷ nhiệm, không bằng handler trên từng thẻ ══
 *
 * Thẻ do máy chủ dựng, nên không gắn được `onMouseEnter` lên chúng — hàm
 * không đi qua ranh giới server/client. Thay vào đó `ScreenCard` in
 * `data-slug`, và một handler duy nhất ở đây đọc `closest('[data-slug]')`.
 * Cách này còn rẻ hơn: 24 thẻ là 24 listener, ở đây là một.
 */

/** Thẻ sáng bao lâu sau khi bấm pin, tính bằng mili giây. */
const SANG_MS = 1600;

export type KieuXem = 'grid' | 'map';

/**
 * Đường dẫn của kiểu xem kia, giữ nguyên mọi tham số lọc đang có.
 *
 * `page` được giữ: đổi cách nhìn không phải đổi tập kết quả, nên bỏ người
 * dùng về trang 1 là lấy mất chỗ họ đang đứng.
 */
function duongDan(query: Record<string, string | undefined>, view: KieuXem): string {
    const u = new URLSearchParams();

    for (const [k, v] of Object.entries(query)) {
        if (v && k !== 'view') u.set(k, v);
    }

    if (view === 'map') u.set('view', 'map');

    const qs = u.toString();

    return qs ? `/explore?${qs}` : '/explore';
}

function DoiKieuXem({
    query,
    kieuXem,
}: {
    query: Record<string, string | undefined>;
    kieuXem: KieuXem;
}) {
    return (
        <div className="ex-doi-xem" role="group" aria-label="Kiểu xem">
            <Link
                href={duongDan(query, 'grid')}
                className={kieuXem === 'grid' ? 'on' : undefined}
                aria-current={kieuXem === 'grid' ? 'page' : undefined}
            >
                Lưới
            </Link>
            <Link
                href={duongDan(query, 'map')}
                className={kieuXem === 'map' ? 'on' : undefined}
                aria-current={kieuXem === 'map' ? 'page' : undefined}
            >
                Bản đồ
            </Link>
        </div>
    );
}

export function KhoiExplore({
    kieuXem,
    tong,
    sort,
    query,
    boLoc,
    oTim,
    pagination,
    children,
}: {
    kieuXem: KieuXem;
    tong: number;
    sort: string;
    /** Tham số URL hiện tại — cho ô sắp xếp, cho bản đồ và cho hai liên kết. */
    query: Record<string, string | undefined>;
    /**
     * Dải chip lọc — **cả hai** kiểu xem dựng nó (lát 3, 22/10/2026).
     *
     * Kiểu lưới đặt nó trên lưới, kiểu bản đồ đặt nó vào đầu panel. Cùng một
     * `BoLocKho`, vì hai trang này vừa gộp lại chính vì chúng đã có hai bộ
     * lọc trôi khỏi nhau.
     */
    boLoc: ReactNode;
    /** Ô tìm kiếm lớn — ở kiểu bản đồ nó nằm trong đầu panel. */
    oTim: ReactNode;
    pagination: ReactNode;
    children: ReactNode;
}) {
    const [slugSang, setSlugSang] = useState<string | null>(null);
    const [moPanel, setMoPanel] = useState(false);

    /**
     * Vị trí người dùng, xin khi mở trang (lát định vị, 22/10/2026).
     *
     * Gọi ở **đây**, không trong nhánh bản đồ: lưới là kiểu xem mặc định của
     * `/explore`, và chủ dự án chốt hỏi khi mở trang chứ không khi mở riêng
     * kiểu bản đồ. Hook không gọi có điều kiện được, nên nó phải nằm trên
     * câu lệnh rẽ nhánh kiểu xem phía dưới.
     *
     * Ba lưới an toàn nằm trong `dung-vi-tri.ts` và `vi-tri.ts`, có chốt
     * riêng ở `tests/js/vi-tri.test.mjs`.
     */
    const { trangThai: viTri } = dungViTri();

    const toaDo = viTri.pha === 'co' ? { lat: viTri.lat, lng: viTri.lng } : null;

    const kqRef = useRef<HTMLDivElement>(null);

    /**
     * Bấm pin: cuộn tới thẻ tương ứng và làm nó sáng lên.
     *
     * Một cụm có thể gom nhiều màn hình cùng toà nhà, và bản đồ hiện tới 500
     * vị trí trong khi danh sách chỉ có 24 — nên thử lần lượt từng slug, và
     * nếu không slug nào có thẻ trên trang thì không làm gì. Popup của bản đồ
     * vẫn mở, và nó có đường sang trang chi tiết.
     */
    const chonCum = useCallback((slugs: string[]) => {
        const goc = kqRef.current;
        if (!goc) return;

        for (const slug of slugs) {
            const the = goc.querySelector<HTMLElement>(`[data-slug="${CSS.escape(slug)}"]`);
            if (!the) continue;

            the.scrollIntoView({ behavior: 'smooth', block: 'center' });
            the.classList.add('ex-the-sang');
            window.setTimeout(() => the.classList.remove('ex-the-sang'), SANG_MS);

            return;
        }
    }, []);

    function reVao(e: React.MouseEvent<HTMLDivElement>) {
        const the = (e.target as HTMLElement).closest<HTMLElement>('[data-slug]');

        setSlugSang(the?.dataset.slug ?? null);
    }

    if (kieuXem === 'grid') {
        return (
            <div className="w">
                {/*
                  Một cột, không hai (lát 3, 22/10/2026).
                  `.listing-layout` vẫn giữ khoảng cách trên dưới dùng chung
                  với `/products`, nhưng `.ex-mot-cot` tắt cột lọc 260px: bộ
                  lọc nay là dải chip nằm ngang phía trên, nên lưới nhận cả
                  1176px và lên 4 thẻ 280px.
                */}
                <div className="listing-layout ex-mot-cot">
                    {boLoc}

                    {/*
                      Dòng định vị đứng giữa bộ lọc và lưới: nó nói về tập
                      kết quả, nên nó thuộc về chỗ ngay trên tập đó. Ở kiểu
                      lưới nó đếm thật — xem docblock của `BangViTri`.
                    */}
                    <BangViTri trangThai={viTri} dem />

                    <div
                        ref={kqRef}
                        className="ex-cot-kq"
                        onMouseOver={reVao}
                        onMouseLeave={() => setSlugSang(null)}
                    >
                        <KhoiKetQua
                            tong={tong}
                            sort={sort}
                            basePath="/explore"
                            query={query}
                            nutThem={<DoiKieuXem query={query} kieuXem="grid" />}
                        >
                            {children}
                        </KhoiKetQua>

                        {pagination}
                    </div>
                </div>

                {/*
                  Thanh đáy của danh sách rút gọn (lát 4). Nó tự ẩn khi chưa
                  chọn gì, nên không có nhánh điều kiện ở đây — và nó nằm
                  ngoài lưới vì `position: fixed`.
                */}
                <ThanhDanhSach />
            </div>
        );
    }

    return (
        <KhungBanDo
            tong={tong}
            sort={sort}
            query={query}
            oTim={oTim}
            boLoc={boLoc}
            pagination={pagination}
            moPanel={moPanel}
            setMoPanel={setMoPanel}
            kqRef={kqRef}
            reVao={reVao}
            slugSang={slugSang}
            setSlugSang={setSlugSang}
            chonCum={chonCum}
            viTri={viTri}
            toaDo={toaDo}
        >
            {children}
        </KhungBanDo>
    );
}

/**
 * Kiểu xem bản đồ — tách ra vì nó gọi `dungKeoSheet`.
 *
 * Hook không được gọi có điều kiện, nên nhánh bản đồ phải là component riêng
 * chứ không phải một nhánh `if` trong thân hàm trên.
 */
function KhungBanDo({
    tong,
    sort,
    query,
    oTim,
    boLoc,
    pagination,
    moPanel,
    setMoPanel,
    kqRef,
    reVao,
    setSlugSang,
    slugSang,
    chonCum,
    viTri,
    toaDo,
    children,
}: {
    tong: number;
    sort: string;
    query: Record<string, string | undefined>;
    oTim: ReactNode;
    boLoc: ReactNode;
    pagination: ReactNode;
    moPanel: boolean;
    setMoPanel: (v: boolean) => void;
    kqRef: React.RefObject<HTMLDivElement | null>;
    reVao: (e: React.MouseEvent<HTMLDivElement>) => void;
    setSlugSang: (v: string | null) => void;
    slugSang: string | null;
    chonCum: (slugs: string[]) => void;
    viTri: TrangThaiViTri;
    toaDo: { lat: number; lng: number } | null;
    children: ReactNode;
}) {
    const dongPanel = useCallback(() => setMoPanel(false), [setMoPanel]);

    /**
     * Bốn con số của khung nhìn, do `BanDoKetQua` đẩy lên.
     *
     * Dải tổng quan là anh em của khung bản đồ chứ không nằm trong nó — nó
     * đứng ở đầu panel danh sách, nơi nó còn nhìn thấy được khi người dùng
     * đang kéo bản đồ. `khiDoiTomTat` phải là `useCallback`: hàm mới mỗi
     * lượt render thì effect bên kia chạy lại mỗi lượt và hai bên setState
     * vào nhau không dừng.
     */
    const [tomTat, setTomTat] = useState<TomTat | null>(null);
    const nhanTomTat = useCallback((t: TomTat | null) => setTomTat(t), []);

    /**
     * Thẻ chi tiết mở cạnh danh sách (lát 5, 22/10/2026).
     *
     * Trạng thái là **đường dẫn**, không phải một biến: mở thẻ thì địa chỉ
     * thành `/explore/{slug}` và Back đóng nó. Xem docblock của `TheChiTiet`
     * về việc vì sao dùng `history.pushState` chứ không `router.push`.
     */
    const [slugChiTiet, setSlugChiTiet] = useState<string | null>(null);

    const moChiTiet = useCallback((slug: string) => {
        setSlugChiTiet(slug);
        window.history.pushState(null, '', `/explore/${encodeURIComponent(slug)}`);
    }, []);

    /** Đóng bằng cách lùi lịch sử, nên nút × và nút Back làm cùng một việc. */
    const dongChiTiet = useCallback(() => window.history.back(), []);

    /**
     * Back và Forward đều đọc lại từ địa chỉ, không chỉ "đóng".
     *
     * Đóng thẳng thì nút Forward — quay lại thẻ vừa xem — chỉ đổi địa chỉ mà
     * không mở gì, và người dùng đứng trước một URL nói một đằng còn màn hình
     * một nẻo.
     */
    useEffect(() => {
        const doi = () => {
            const m = window.location.pathname.match(/^\/explore\/([^/]+)\/?$/);

            setSlugChiTiet(m ? decodeURIComponent(m[1]) : null);
        };

        window.addEventListener('popstate', doi);

        return () => window.removeEventListener('popstate', doi);
    }, []);

    /**
     * Bấm một thẻ trong panel mở chi tiết **tại chỗ**, không rời trang.
     *
     * Uỷ nhiệm trên khung cuộn thay vì gắn vào từng thẻ: thẻ do máy chủ dựng
     * nên không nhận được hàm, và cả thẻ là một `<a href="/explore/{slug}">`
     * — xem docblock của `ScreenCard`. Cùng cách phép rê chuột đã làm.
     *
     * ══ Pha CAPTURE, không phải bubble ══
     *
     * `onClick` trên khung cuộn **không chạy kịp**. Thẻ là một `<Link>` của
     * Next, và `Link` gắn `onClick` của riêng nó lên chính thẻ `<a>`; React
     * gọi handler của con trước handler của cha, nên lúc cú bấm tới đây thì
     * Next đã bắt đầu điều hướng. Đo thấy ngay ở lần chạy đầu: bấm một thẻ
     * là cả trang bản đồ biến mất, đúng thứ lát này sinh ra để tránh.
     *
     * `onClickCapture` chạy trước mọi handler bubble trong cây, và
     * `stopPropagation()` ở đó cắt luôn đường tới `onClick` của `Link`.
     *
     * Ba trường hợp **không** chặn, vì chặn chúng là lấy mất một hành vi
     * trình duyệt mà người dùng cố ý gọi: bấm giữa hoặc kèm
     * Ctrl/Cmd/Shift/Alt (mở tab mới), và cú bấm rơi vào ô tick bên trong
     * thẻ — ô tick là một nút khác, nằm lồng trong thẻ vì cả thẻ là một
     * `<a>`.
     */
    const bamThe = useCallback(
        (e: React.MouseEvent<HTMLDivElement>) => {
            if (e.defaultPrevented || e.button !== 0) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            const dich = e.target as HTMLElement;

            if (dich.closest('.o-tick')) return;

            const a = dich.closest<HTMLAnchorElement>('a[data-slug]');
            const slug = a?.dataset.slug;

            if (!a || !slug) return;

            e.preventDefault();
            e.stopPropagation();
            moChiTiet(slug);
        },
        [moChiTiet],
    );

    /**
     * Cùng ngăn kéo hai bậc với `/map` và cột lọc (#145), không phải bản thứ
     * ba. Trên desktop CSS cho panel về thẻ nổi tĩnh và tay nắm ẩn, nên hook
     * vẫn gắn nhưng không có gì để kéo.
     */
    const { refNgan, rong, tay } = dungKeoSheet<HTMLElement>({
        mo: moPanel,
        dong: dongPanel,
        nhan: 'danh sách',
    });

    /**
     * Desktop mở sẵn panel, mobile thì không.
     *
     * Đọc trong effect chứ không lúc render: máy chủ không có `matchMedia`,
     * nên đọc lúc render làm HTML hai phía lệch nhau. Một nhịp đầu panel đóng
     * ở cả hai, rồi mở ra trên desktop — và vì CSS ≥1024px cho `.ex-panel`
     * hiện bất kể lớp `open`, người dùng desktop không thấy nhịp đó.
     */
    useEffect(() => {
        const mq = window.matchMedia('(min-width: 1024px)');
        const doi = () => setMoPanel(mq.matches);

        doi();
        mq.addEventListener('change', doi);

        return () => mq.removeEventListener('change', doi);
    }, [setMoPanel]);

    const lopPanel = moPanel
        ? rong
            ? 'ex-panel open sheet-rong'
            : 'ex-panel open'
        : 'ex-panel';

    return (
        <>
            <div className="ex-app">
                <BanDoKetQua
                    thamSo={query}
                    slugSang={slugSang}
                    khiChonCum={chonCum}
                    khiDoiTomTat={nhanTomTat}
                    khiMoChiTiet={moChiTiet}
                    viTri={toaDo}
                    hien
                />

                <aside ref={refNgan} className={lopPanel}>
                    <div className="ex-panel-tay" {...tay}>
                        <div className="mp-drag-bar" />
                    </div>

                    {/*
                      Đầu panel: ô tìm, rồi dải chip lọc (lát 3, 22/10/2026).

                      Trong 400px thì sáu chip không vừa một hàng, nên CSS cho
                      hàng này cuộn ngang — đúng cách Google Maps bày dải chip
                      của nó. Bảng rớt thì neo vào `.ex-panel-dau` chứ không
                      vào từng chip, vì một tấm nổi neo *bên trong* vùng cuộn
                      ngang sẽ bị chính vùng đó cắt mất.

                      Hai liên kết đổi kiểu xem rời hàng này xuống thanh kết
                      quả — đo ở 400px: đứng cạnh chip chúng lấy 123px và dải
                      chip chỉ còn 241, tức **2 chip** nhìn thấy trên năm. Ở
                      thanh kết quả chúng đứng cùng chỗ với kiểu lưới, nên
                      người dùng tìm chúng ở một chỗ duy nhất.
                    */}
                    <div className="ex-panel-dau">
                        {oTim}

                        <div className="ex-panel-loc">{boLoc}</div>

                        {/*
                          Dải tổng quan ở ĐẦU panel, không trong vùng cuộn.

                          Nó trả lời "vùng này có gì" và câu trả lời đổi mỗi
                          lần người dùng bấm "Tìm trong khu vực này" — để nó
                          cuộn khuất là để người dùng bỏ lỡ đúng cái thay đổi
                          họ vừa yêu cầu. Giá phải trả: đầu panel cao thêm
                          ~48px, và trên mobile ngăn kéo còn hai bậc để bù.
                        */}
                        {tomTat ? <DaiTongQuan tomTat={tomTat} /> : null}

                        {/*
                          Ở kiểu bản đồ, dòng này KHÔNG đếm: bản đồ đã tự bay
                          tới người dùng và dải tổng quan ngay trên đã nói
                          khung nhìn có bao nhiêu vị trí. Đếm lại là một lời
                          gọi API thừa cho một con số đã có trên màn hình.
                        */}
                        <BangViTri trangThai={viTri} />
                    </div>

                    <div
                        ref={kqRef}
                        className="ex-panel-cuon"
                        onMouseOver={reVao}
                        onMouseLeave={() => setSlugSang(null)}
                        onClickCapture={bamThe}
                    >
                        <KhoiKetQua
                            tong={tong}
                            sort={sort}
                            basePath="/explore"
                            query={query}
                            kieuXem={false}
                            nutThem={<DoiKieuXem query={query} kieuXem="map" />}
                        >
                            {children}
                        </KhoiKetQua>

                        {pagination}
                    </div>
                </aside>

                {/*
                  Thẻ chi tiết — anh em của panel danh sách, không con của
                  nó: nó mở CẠNH danh sách ở left:428px, và nằm trong một
                  panel `overflow:hidden` rộng 400 thì không có chỗ nào để
                  mở ra.
                */}
                {slugChiTiet ? (
                    <TheChiTiet key={slugChiTiet} slug={slugChiTiet} dong={dongChiTiet} />
                ) : null}

                {moPanel ? null : (
                    <button type="button" className="ex-mo-panel" onClick={() => setMoPanel(true)}>
                        Danh sách · {new Intl.NumberFormat('vi-VN').format(tong)}
                    </button>
                )}
            </div>

            <ThanhDanhSach />
        </>
    );
}
