<?php

namespace Tests\Feature\Filament;

use App\Models\Screen;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

/**
 * Tên tham số closure của filter — `sửa năm bộ lọc dự toán`, 26/10/2026.
 *
 * ══ Cái bẫy ══
 *
 * `CLAUDE.md` §4 đã ghi: *"Tham số closure của filter phải đặt tên `$query`,
 * nếu đặt tên khác Filament tạo Builder rỗng và filter mất tác dụng."* Năm bộ
 * lọc của hai trang dự toán vẫn viết `fn (Builder $q, array $data)`, nên luật
 * ấy chỉ sống trong một tệp Markdown. Tệp này biến nó thành một phép đo.
 *
 * Đường đi, đọc từ vendor chứ không đoán:
 *
 * 1. `Filters\Concerns\InteractsWithTableQuery::apply()` gọi
 *    `$this->evaluate($this->modifyQueryUsing, ['data' => …, 'query' => $query,
 *    'state' => …])` — ba **tên**, và nó **bỏ giá trị trả về** của closure rồi
 *    `return $query`. Nên closure phải đột biến đúng cái Builder được đưa vào.
 * 2. `Support\Concerns\EvaluatesClosures::resolveClosureDependencyForEvaluation()`
 *    tra tên trước (`$q` không khớp `data|query|state`), rồi `$typedInjections`
 *    (ở đây rỗng), rồi `resolveDefaultClosureDependencyForEvaluationByName`
 *    (`BaseFilter` chỉ biết `livewire`, `table`), rồi `…ByType` (`BaseFilter`
 *    **không** override — kiểu `Builder` không được biết tới), và cuối cùng
 *    `app()->make(Illuminate\Database\Eloquent\Builder::class)`.
 * 3. Container dựng được một Builder — **rỗng, không model, trên connection
 *    mặc định**. Mọi `->where()` của closure áp lên nó, rồi nó bị quăng đi.
 *
 * Hai hậu quả khác nhau, và cả hai đều có ca đo dưới đây:
 *
 * | Hình dạng closure | Hậu quả |
 * |---|---|
 * | `$q->where(...)` | **im lặng** — bật filter, danh sách không đổi |
 * | `$q->whereHas(...)` | **nổ** — Builder rỗng không có model để lần quan hệ |
 *
 * ══ Và ba hình dạng KHÔNG phải lỗi ══
 *
 * Cùng một cái tên `$q` lại hoàn toàn đúng ở ba chỗ, vì chúng không đi qua
 * `evaluate()` mà gọi closure **theo vị trí**:
 *
 * - `TernaryFilter::queries(true:, false:)` → `$true($query, $data)` (vendor
 *   `TernaryFilter.php:148`)
 * - `->when()` / `->unless()` của Laravel `Conditionable`
 *
 * Nên bộ quét cố ý **không** bắt chúng: một bộ quét bắt cả những chỗ đang đúng
 * thì lần sửa kế tiếp sẽ là một lần sửa không cần thiết, và người sửa sẽ học
 * sai luật.
 *
 * ══ Phân vai với `ThamSoQueryPhaiTenQueryTest` ══
 *
 * Bộ quét mã nguồn **không** nằm ở đây. Nó đã có từ lát 3 của plan ảnh, ở
 * `ThamSoQueryPhaiTenQueryTest`, và chính nó ghim năm chỗ sai cùng text để
 * duyệt `sửa năm bộ lọc dự toán` — tức lệnh sinh ra tệp này. Hai tệp chia việc:
 *
 * | Tệp | Trả lời |
 * |---|---|
 * | `ThamSoQueryPhaiTenQueryTest` | **ở đâu** — không chỗ nào trong `app/Filament` lặp lại |
 * | tệp này | **vì sao** — cái bẫy sập thật, và ba hình dạng giống nó thì không |
 *
 * Một bộ quét mã nguồn không chứng minh được hành vi: nó so chuỗi. Nếu một bản
 * Filament sau này dạy container kiểu `Builder` thì cái bẫy biến mất mà bộ quét
 * vẫn đỏ như cũ — hai ca đầu của tệp này sẽ là chỗ đỏ lên để nói điều đó.
 */
class BoLocDuToanTest extends TestCase
{
    // ══ Cái bẫy, đo trên filter dựng tại chỗ ════════════════════════════

    public function test_closure_dat_ten_khac_query_lam_filter_bat_dong(): void
    {
        $that = Screen::query();
        $truoc = $that->toSql();

        $filter = SelectFilter::make('x')
            ->query(fn (Builder $q, array $data) => $q->where('name', 'ABC'));

        $ra = $filter->apply($that, ['value' => 'v', 'isActive' => true]);

        // Builder trả về vẫn là builder thật — `apply()` luôn `return $query`.
        $this->assertSame($that, $ra);

        // Nhưng điều kiện của closure không có trong đó.
        $this->assertSame($truoc, $that->toSql(), 'Filter đặt tên `$q` lại có tác dụng — cái bẫy đã được Filament sửa, đọc lại vendor.');
        $this->assertStringNotContainsString('name', $that->toSql());
    }

    public function test_closure_dat_ten_query_thi_filter_co_tac_dung(): void
    {
        $that = Screen::query();

        $filter = SelectFilter::make('x')
            ->query(fn (Builder $query, array $data) => $query->where('name', 'ABC'));

        $filter->apply($that, ['value' => 'v', 'isActive' => true]);

        $this->assertStringContainsString('`name` = ?', $that->toSql());
        $this->assertSame(['ABC'], $that->getBindings());
    }

    public function test_cai_duoc_dua_vao_la_mot_builder_khac_khong_co_bang(): void
    {
        $that = Screen::query();
        $batDuoc = null;

        $filter = SelectFilter::make('x')
            ->query(function (Builder $q) use (&$batDuoc) {
                $batDuoc = $q;
            });

        $filter->apply($that, ['value' => 'v', 'isActive' => true]);

        $this->assertInstanceOf(Builder::class, $batDuoc, 'Không nhận được Builder nào — đường đi đã khác.');
        $this->assertNotSame($that, $batDuoc, 'Filament đưa đúng builder thật vào `$q` — cái bẫy không còn.');

        // Builder của container không có bảng: `from` trống, nên nó không thể
        // là builder của một bảng nào.
        $this->assertNull($batDuoc->getQuery()->from);
    }

    public function test_where_has_tren_builder_bat_dong_thi_no_chu_khong_im_lang(): void
    {
        // Hai trong năm bộ lọc dự toán (`city`, `indoor_outdoor`) dùng
        // `whereHas('screen', …)`. Builder của container không có model, nên
        // đây không phải một filter im lặng mà là một trang 500 — nhưng chỉ
        // khi người dùng **chọn** một giá trị, nên nó lọt qua mọi lượt mở
        // trang.
        $filter = SelectFilter::make('x')
            ->query(fn (Builder $q, array $data) => $q->whereHas('screen', fn ($r) => $r->where('city', 'Hà Nội')));

        $this->expectException(\Error::class);

        $filter->apply(Screen::query(), ['value' => 'Hà Nội', 'isActive' => true]);
    }

    // ══ Cùng cái bẫy ở cột: `searchable(query:)` và `sortable(query:)` ══

    public function test_cot_searchable_dat_ten_khac_query_cung_bat_dong(): void
    {
        $that = Screen::query();
        $truoc = $that->toSql();
        $dau = true;

        TextColumn::make('name')
            ->searchable(query: fn (Builder $q, string $search) => $q->where('name', 'like', "%{$search}%"))
            ->applySearchConstraint($that, 'abc', $dau);

        $this->assertSame($truoc, $that->toSql(), 'Cột tìm kiếm đặt tên `$q` lại có tác dụng — đọc lại vendor.');
    }

    public function test_cot_searchable_dat_ten_query_thi_tim_duoc(): void
    {
        $that = Screen::query();
        $dau = true;

        TextColumn::make('name')
            ->searchable(query: fn (Builder $query, string $search) => $query->where('name', 'like', "%{$search}%"))
            ->applySearchConstraint($that, 'abc', $dau);

        $this->assertStringContainsString('`name` like ?', $that->toSql());
        $this->assertSame(['%abc%'], $that->getBindings());
    }

    public function test_cot_sortable_dat_ten_khac_query_cung_bat_dong(): void
    {
        $that = Screen::query();

        TextColumn::make('name')
            ->sortable(query: fn (Builder $q, string $direction) => $q->orderBy('name', $direction))
            ->applySort($that, 'desc');

        $this->assertStringNotContainsString('order by', $that->toSql());
    }

    public function test_cot_sortable_dat_ten_query_thi_sap_duoc(): void
    {
        $that = Screen::query();

        TextColumn::make('name')
            ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('name', $direction))
            ->applySort($that, 'desc');

        $this->assertStringContainsString('order by `name` desc', $that->toSql());
    }

    // ══ Ba hình dạng KHÔNG phải lỗi — ghim để không ai "sửa" lan ra ════

    public function test_ternary_filter_goi_closure_theo_vi_tri_nen_ten_nao_cung_chay(): void
    {
        $that = Screen::query();

        // `TernaryFilter::queries()` bọc hai closure trong một closure có tham
        // số tên `$query` rồi gọi `$true($query, $data)` — theo **vị trí**.
        TernaryFilter::make('x')
            ->queries(
                true: fn (Builder $q) => $q->whereNotNull('name'),
                false: fn (Builder $q) => $q->whereNull('name'),
            )
            ->apply($that, ['value' => true, 'isActive' => true]);

        $this->assertStringContainsString('`name` is not null', $that->toSql());
    }

    public function test_when_cua_laravel_goi_closure_theo_vi_tri(): void
    {
        $that = Screen::query()->when(
            true,
            fn (Builder $q) => $q->where('active', true),
            fn (Builder $q) => $q->whereRaw('1 = 0'),
        );

        $this->assertStringContainsString('`active` = ?', $that->toSql());
    }

}
