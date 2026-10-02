<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Site::class);

        $sites = QueryBuilder::for(Site::class)
            ->allowedFilters(['status','city','region','country'])
            ->allowedSorts(['name','created_at','city'])
            ->withCount('screens')
            ->paginate(20);
        return response()->json($sites);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Site::class);

        $data = $request->validate([
            // owner_id chỉ super_admin mới được chỉ định; xem phần suy owner bên dưới.
            'owner_id'    => 'sometimes|ulid|exists:owners,id',
            'external_id' => 'required|string|max:75',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'lat'         => 'nullable|numeric|between:-90,90',
            'lon'         => 'nullable|numeric|between:-180,180',
            'address'     => 'nullable|string',
            'city'        => 'nullable|string',
            'region'      => 'nullable|string',
            'country'     => 'nullable|string|size:2',
        ]);

        $data['owner_id'] = $this->resolveOwnerId($request, $data['owner_id'] ?? null);

        return response()->json(Site::create($data), 201);
    }

    /**
     * owner_id suy từ tenant đang đăng nhập, không nhận từ client.
     * Trước đây chỉ validate exists:owners,id nên publisher của owner A tạo được
     * địa điểm cho owner B (audit 23/09, F01).
     */
    private function resolveOwnerId(Request $request, ?string $requested): string
    {
        $user = $request->user();

        if ($user->hasRole('super_admin')) {
            abort_if(! $requested, 422, 'super_admin phải chỉ định owner_id.');
            return $requested;
        }

        abort_if(
            $requested && $requested !== $user->current_owner_id,
            403,
            'Không thể tạo dữ liệu cho media owner khác.'
        );

        abort_if(! $user->current_owner_id, 403, 'Tài khoản chưa chọn media owner.');

        return $user->current_owner_id;
    }

    public function show(Site $site): JsonResponse
    {
        Gate::authorize('view', $site);

        return response()->json(
            $site->load('screens.spec','screens.inventory')->append('screen_count')
        );
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        Gate::authorize("update", $site);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'lat'         => 'nullable|numeric',
            'lon'         => 'nullable|numeric',
            'address'     => 'nullable|string',
            'city'        => 'nullable|string',
            'region'      => 'nullable|string',
            'status'      => 'sometimes|in:active,paused,closed',
        ]);
        $site->update($data);
        return response()->json($site);
    }

    public function destroy(Site $site): JsonResponse
    {
        Gate::authorize("delete", $site);

        $site->delete();
        return response()->json(null,204);
    }
}
