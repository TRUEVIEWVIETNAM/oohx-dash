<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OwnerResource;
use App\Models\Owner;
use App\Services\TenantPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Quản lý media owner qua API.
 *
 * Mọi action đều gọi policy. Trước đây nhóm route này chỉ có auth:sanctum, nên bất
 * kỳ ai có token đều đọc/sửa/xoá được owner của người khác (audit 23/09, F01).
 */
class OwnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Owner::class);

        $user = $request->user();

        $owners = QueryBuilder::for(Owner::class)
            ->allowedFilters(['status', 'type', 'onboard_method'])
            ->allowedSorts(['name', 'created_at'])
            // Không phải super_admin thì chỉ thấy owner mình là thành viên.
            ->unless(
                $user->hasRole('super_admin'),
                fn ($q) => $q->whereIn('id', $user->owners()->pluck('owners.id'))
            )
            ->withCount(['sites', 'screens'])
            ->paginate(20);

        return OwnerResource::collection($owners)->response();
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Owner::class);

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'slug'              => 'required|string|unique:owners,slug',
            'type'              => 'required|in:retailer,media_owner,self',
            'onboard_method'    => 'required|in:cms,api,vast,hardware',
            'revenue_share_pct' => 'nullable|numeric|min:0|max:100',
            'billing_info'      => 'nullable|array',
            'notes'             => 'nullable|string',
        ]);

        return (new OwnerResource(Owner::create($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Owner $owner): JsonResponse
    {
        Gate::authorize('view', $owner);

        return (new OwnerResource(
            $owner->load(['sites', 'networks'])->append(['total_screens', 'programmatic_screens'])
        ))->response();
    }

    public function update(Request $request, Owner $owner): JsonResponse
    {
        Gate::authorize('update', $owner);

        $rules = [
            'name'           => 'sometimes|string|max:255',
            'onboard_method' => 'sometimes|in:cms,api,vast,hardware',
        ];

        // Trạng thái duyệt và điều khoản tài chính là việc của sàn, không phải của
        // thành viên tenant — dù họ có quyền sửa hồ sơ owner.
        if ($request->user()->hasRole('super_admin')) {
            $rules['status']            = 'sometimes|in:pending,active,suspended';
            $rules['revenue_share_pct'] = 'sometimes|numeric|min:0|max:100';
            $rules['billing_info']      = 'sometimes|nullable|array';
        }

        $owner->update($request->validate($rules));

        return (new OwnerResource($owner->fresh()))->response();
    }

    public function destroy(Owner $owner): JsonResponse
    {
        Gate::authorize('delete', $owner);

        $owner->delete();

        return response()->json(null, 204);
    }

    public function switchContext(Owner $owner, Request $request): JsonResponse
    {
        Gate::authorize('view', $owner);

        if (! $request->user()->switchOwner($owner->id)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return response()->json([
            'message' => 'Switched',
            'owner'   => new OwnerResource($owner),
        ]);
    }

    public function stats(Request $request, Owner $owner): JsonResponse
    {
        Gate::authorize('view', $owner);

        // Doanh thu là báo cáo, không phải hồ sơ owner: dùng view_reports theo đúng
        // bảng quyền đang có (owner/manager/reporting_only/sales_manager).
        // Trước đây mọi thành viên, kể cả read_only và operator, đều xem được
        // (Codex review T1, F4). Kiểm trên owner trong URL, không phải tenant đang chọn.
        abort_unless(
            $request->user()->hasRole('super_admin')
                || TenantPermission::for($request->user(), $owner->id)->can('view_reports'),
            403,
            'Tài khoản không có quyền xem báo cáo của media owner này.'
        );

        // Đếm theo owner trong URL, bỏ owner_scope: quyền đã kiểm ở trên, còn scope
        // lại lọc theo tenant ĐANG CHỌN nên user thuộc cả A và B mà đang ở A sẽ thấy
        // số màn hình của B bằng 0 trong khi doanh thu vẫn ra số (Codex follow-up, mục 3).
        $screens = fn () => $owner->screens()->withoutGlobalScope('owner_scope');

        return response()->json([
            'total_screens'        => $screens()->count(),
            'active_screens'       => $screens()->where('active', true)->count(),
            'online_screens'       => $screens()->where('status', 'online')->count(),
            'programmatic_screens' => $screens()
                ->whereHas('inventory', fn ($q) => $q->where('programmatic_enabled', true))->count(),
            'total_sites'          => $owner->sites()->withoutGlobalScope('owner_scope')->count(),
            'revenue_last_30d'     => DB::table('impression_logs')
                ->where('owner_id', $owner->id)
                ->where('played_at', '>=', now()->subDays(30))->sum('revenue_owner'),
            'impressions_last_30d' => DB::table('impression_logs')
                ->where('owner_id', $owner->id)
                ->where('played_at', '>=', now()->subDays(30))->sum('imp_count'),
        ]);
    }
}
