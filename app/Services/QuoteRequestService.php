<?php

namespace App\Services;

use App\Models\QuoteRequest;
use App\Models\Screen;
use App\Support\PhanTrangOnDinh;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Yêu cầu báo giá — nghiệp vụ, một chỗ (lát 5).
 *
 * CLAUDE.md mục 1: Filament, API và lệnh artisan đều gọi **cùng** service
 * này. Hai nơi cùng quyết định "owner có được thấy liên hệ chưa" là hai nơi
 * có thể trôi, và lần trôi đó là một lần rò dữ liệu cá nhân.
 */
class QuoteRequestService
{
    use PhanTrangOnDinh;

    /** Trần số vị trí một yêu cầu, đúng bằng trần của danh sách rút gọn. */
    public const TOI_DA_MUC = 50;

    public function __construct(private PolicyConsentService $consents)
    {
    }

    /**
     * Tạo một yêu cầu từ dữ liệu đã validate.
     *
     * ══ Slug được tra lại ở máy chủ, không tin client ══
     *
     * Client gửi danh sách slug; tên màn hình, thành phố và **owner** đều do
     * máy chủ tra ra. Nhận tên từ client là để người gửi tự khai mình đang
     * hỏi "Màn hình Nguyễn Huệ" trong khi slug trỏ tới một màn hình khác —
     * và owner nhận được một yêu cầu nói sai về chính kho của mình.
     *
     * Slug không có thật hoặc không công khai thì **bị bỏ**, không làm hỏng
     * cả yêu cầu: danh sách rút gọn sống trong `localStorage` hàng tuần, và
     * một màn hình gỡ khỏi sàn trong lúc đó là chuyện bình thường. Nhưng nếu
     * **không slug nào** còn hợp lệ thì có lỗi thật, và `$this->locSlug()`
     * trả mảng rỗng để người gọi trả 422.
     *
     * @param  array{slugs: string[], contact_name: string, contact_email: string, ...}  $data
     */
    public function tao(array $data, Request $request): QuoteRequest
    {
        $manHinh = $this->traManHinh($data['slugs']);

        return DB::transaction(function () use ($data, $request, $manHinh) {
            $yeuCau = QuoteRequest::create([
                'code'            => $this->sinhMa(),
                'organization_id' => $data['organization_id'] ?? null,
                'contact_name'    => $data['contact_name'],
                'contact_email'   => $data['contact_email'],
                'contact_phone'   => $data['contact_phone']   ?? null,
                'contact_company' => $data['contact_company'] ?? null,
                'campaign_start'  => $data['campaign_start']  ?? null,
                'campaign_end'    => $data['campaign_end']    ?? null,
                'budget_vnd'      => $data['budget_vnd']      ?? null,
                'notes'           => $data['notes']           ?? null,
                'status'          => QuoteRequest::STATUS_PENDING,
                'submitted_ip'    => $request->ip(),
                'user_agent'      => substr((string) $request->userAgent(), 0, 512),
            ]);

            $yeuCau->items()->createMany(
                $manHinh->map(fn (Screen $s) => [
                    'owner_id'    => $s->owner_id,
                    'screen_id'   => $s->id,
                    'screen_slug' => $s->slug,
                    'screen_name' => $s->name,
                    'city'        => $s->site?->city,
                ])->all(),
            );

            // Bằng chứng đồng ý chính sách, phiên bản do máy chủ quyết định.
            $this->consents->record(
                ['privacy'],
                'quote_request',
                $request,
                $request->user()?->id,
                $yeuCau->id,
            );

            // CLAUDE.md mục 5: ai, lúc nào, bao nhiêu mục. KHÔNG ghi email và
            // điện thoại vào log — nhật ký ứng dụng không phải nơi giữ dữ
            // liệu cá nhân, và nó đi qua nhiều tay hơn cơ sở dữ liệu.
            Log::info('Yêu cầu báo giá mới', [
                'code'       => $yeuCau->code,
                'so_muc'     => $manHinh->count(),
                'so_owner'   => $manHinh->pluck('owner_id')->unique()->count(),
                'co_tai_khoan' => $request->user() !== null,
                'ip'         => $request->ip(),
            ]);

            return $yeuCau->load('items');
        });
    }

    /**
     * Màn hình **đang công khai** ứng với danh sách slug, giữ đúng thứ tự
     * người dùng chọn và bỏ trùng.
     *
     * @param  string[]  $slugs
     * @return Collection<int, Screen>
     */
    public function traManHinh(array $slugs): Collection
    {
        $slugs = collect($slugs)->filter()->unique()->take(self::TOI_DA_MUC)->values();

        if ($slugs->isEmpty()) {
            return collect();
        }

        $theoSlug = Screen::publiclyVisible()
            ->with('site:id,city')
            ->whereIn('slug', $slugs->all())
            ->get()
            ->keyBy('slug');

        return $slugs->map(fn (string $s) => $theoSlug->get($s))->filter()->values();
    }

    /**
     * Yêu cầu của **một** media owner — đường duy nhất `/publisher` đọc.
     *
     * Lọc qua `whereHas('items')` chứ không join: join làm một yêu cầu có ba
     * vị trí của cùng owner hiện ba lần, và đó là loại lỗi trông như "dữ liệu
     * bị nhân bản" chứ không như một câu truy vấn sai.
     */
    public function choOwner(string $ownerId): Builder
    {
        return QuoteRequest::query()
            ->whereHas('items', fn ($q) => $q->where('owner_id', $ownerId))
            ->with(['items' => fn ($q) => $q->where('owner_id', $ownerId)]);
    }

    public function danhSachChoOwner(string $ownerId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->phanTrang($this->choOwner($ownerId)->latest(), $perPage);
    }

    /**
     * Quản trị duyệt — mốc mở liên hệ cho media owner.
     *
     * Duyệt hai lần không dời mốc: `approved_at` là thời điểm **lần đầu** mở,
     * và một bản ghi nói "đã mở lúc X" thì không được đổi X.
     */
    public function duyet(QuoteRequest $yeuCau, ?int $userId = null): QuoteRequest
    {
        if ($yeuCau->lienHeDaMo()) {
            return $yeuCau;
        }

        $yeuCau->update([
            'status'              => QuoteRequest::STATUS_APPROVED,
            'approved_at'         => now(),
            'approved_by_user_id' => $userId,
        ]);

        Log::info('Duyệt yêu cầu báo giá', [
            'code'    => $yeuCau->code,
            'user_id' => $userId,
        ]);

        return $yeuCau->refresh();
    }

    /**
     * Xoá hẳn một yêu cầu — đường duy nhất, và chỉ quản trị sàn gọi được.
     *
     * Quyền ở `QuoteRequestPolicy::delete()`; hàm này **không** kiểm lại (một
     * điều kiện viết hai nơi là hai nơi có thể trôi). Người gọi phải hỏi
     * policy trước — `/admin` làm điều đó ở `->visible()` và ở `->authorize()`
     * của action.
     *
     * ══ Ghi nhật ký trước khi xoá, không sau ══
     *
     * CLAUDE.md mục 5 đòi "ai, lúc nào, trước sau, lý do". Sau khi `delete()`
     * chạy thì không còn gì để đọc, nên ảnh chụp phải lấy trước. Trong cùng
     * transaction: nếu xoá đổ thì dòng nhật ký nói "đã xoá" là một lời sai —
     * nhưng nó nằm ngoài CSDL nên transaction không gỡ được, vì vậy `Log` gọi
     * **sau** khi `delete()` thành công, với dữ liệu đã chụp trước.
     *
     * Nhật ký mang mã, số mục, owner nào bị mất đầu mối, và lý do. **Không**
     * mang tên, email, điện thoại: xoá bản ghi vì dữ liệu cá nhân rồi chép
     * chính dữ liệu đó sang tệp log là xoá trên giấy.
     */
    public function xoa(QuoteRequest $yeuCau, ?int $userId, string $lyDo): void
    {
        $anhChup = [
            'code'       => $yeuCau->code,
            'trang_thai' => $yeuCau->status,
            'da_duyet'   => $yeuCau->lienHeDaMo(),
            'gui_luc'    => $yeuCau->created_at?->toIso8601String(),
            'so_muc'     => $yeuCau->items()->count(),
            'owner_ids'  => $yeuCau->items()->pluck('owner_id')->unique()->values()->all(),
        ];

        DB::transaction(function () use ($yeuCau) {
            // Xoá tường minh thay vì chỉ dựa vào `cascadeOnDelete` của khoá
            // ngoại: cùng một kết quả, nhưng đọc được ở chỗ này chứ không
            // phải ở migration.
            //
            // Phép đột biến 20/10/2026: bỏ dòng này đi, chốt vẫn xanh — khoá
            // ngoại của MySQL dọn hàng con. Nên nó là lớp thứ hai, không phải
            // thứ làm việc; đừng đọc nó như một dòng được canh.
            $yeuCau->items()->delete();
            $yeuCau->delete();
        });

        Log::info('Xoá yêu cầu báo giá', $anhChup + [
            'user_id' => $userId,
            'ly_do'   => $lyDo,
        ]);
    }

    /**
     * Mã tra cứu: YCB-YYYYMM-XXXX. Cùng dạng với PA- và CPN-.
     *
     * Mã lấy từ bản ghi lớn nhất **đang còn**, nên xoá yêu cầu mới nhất thì
     * yêu cầu sau dùng lại mã đó. Không vỡ gì — mã chỉ cần duy nhất trong
     * bảng — nhưng đọc nhật ký thì hai dòng cùng mã là hai yêu cầu khác nhau.
     */
    public function sinhMa(): string
    {
        $dau = 'YCB-' . now()->format('Ym') . '-';

        $cuoi = QuoteRequest::where('code', 'like', $dau . '%')
            ->orderByDesc('code')
            ->value('code');

        $so = $cuoi ? (int) substr($cuoi, -4) + 1 : 1;

        return $dau . str_pad((string) $so, 4, '0', STR_PAD_LEFT);
    }
}
