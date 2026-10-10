<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ScreenController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize("viewAny", Screen::class);

        $screens = QueryBuilder::for(Screen::class)
            ->allowedFilters([
                'active', 'status', 'player_type',
                AllowedFilter::exact('site_id'),
                AllowedFilter::exact('owner_id'),
                AllowedFilter::callback('venue_type', fn ($q, $v) =>
                    $q->whereHas('inventory', fn ($iq) => $iq->where('venue_type', $v))
                ),
                AllowedFilter::callback('programmatic', fn ($q, $v) =>
                    $q->whereHas('inventory', fn ($iq) => $iq->where('programmatic_enabled', (bool) $v))
                ),
            ])
            ->allowedSorts(['name', 'created_at', 'last_heartbeat_at'])
            ->allowedIncludes(['spec', 'inventory', 'site'])
            ->paginate(20);

        return response()->json($screens);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize("create", Screen::class);

        $data = $request->validate([
            "owner_id"         => "sometimes|ulid|exists:owners,id",
            'site_external_id' => 'required|string',
            'external_id'      => 'required|string|max:75',
            'name'             => 'required|string|max:199',
            'description'      => 'nullable|string',
            'player_type'      => 'sometimes|in:adtrue_android,adtrue_webview,third_party,vast_only',
            'uuid'             => 'nullable|uuid',
            'spec'             => 'nullable|array',
            'spec.width_px'    => 'required_with:spec|integer|min:1',
            'spec.height_px'   => 'required_with:spec|integer|min:1',
            'inventory'        => 'nullable|array',
        ]);

        // owner_id suy từ tenant đang đăng nhập, không nhận từ client (audit F01).
        $data['owner_id'] = $this->resolveOwnerId($request, $data['owner_id'] ?? null);

        // Tạo mới cũng có thể kèm giá ⇒ phải kiểm quyền giá TRƯỚC khi ghi bất cứ gì.
        $this->authorizePricingForOwner($request, $data['owner_id']);

        $site = Site::where('owner_id', $data['owner_id'])
            ->where('external_id', $data['site_external_id'])
            ->first();

        if (! $site) {
            return response()->json(['message' => 'Site not found'], 422);
        }

        $screen = Screen::create([
            'owner_id'    => $data['owner_id'],
            'site_id'     => $site->id,
            'external_id' => $data['external_id'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'player_type' => $data['player_type'] ?? 'adtrue_android',
            'uuid'        => $data['uuid'] ?? null,
            'active'      => true,
            'status'      => 'offline',
        ]);

        if (! empty($data['spec'])) {
            $this->saveSpec($screen, $data['spec']);
        }
        if (! empty($data['inventory'])) {
            $this->saveInventory($screen, $data['inventory']);
        }

        return response()->json($screen->load(['spec', 'inventory']), 201);
    }

    /**
     * owner_id suy từ tenant đang đăng nhập, không nhận từ client.
     */
    private function resolveOwnerId(Request $request, ?string $requested): string
    {
        $user = $request->user();

        if ($user->hasRole("super_admin")) {
            abort_if(! $requested, 422, "super_admin phải chỉ định owner_id.");
            return $requested;
        }

        abort_if(
            $requested && $requested !== $user->current_owner_id,
            403,
            "Không thể tạo dữ liệu cho media owner khác."
        );

        abort_if(! $user->current_owner_id, 403, "Tài khoản chưa chọn media owner.");

        return $user->current_owner_id;
    }

    /**
     * Payload có đụng tới giá thì phải có quyền `manage_pricing`.
     *
     * `manage_inventory` cho phép cả operator, nhưng operator không được sửa giá
     * (Codex review T1, F1). Kiểm theo nội dung request chứ không theo endpoint.
     */
    private function authorizePricingPayload(Request $request, Screen $screen): void
    {
        if ($this->payloadTouchesPricing($request)) {
            Gate::authorize('managePricing', $screen);
        }
    }

    /**
     * Bản dùng cho `store`: chưa có Screen nên kiểm quyền trên owner sắp tạo.
     * Phải gọi TRƯỚC Screen::create, không được chặn sau khi đã ghi một phần.
     */
    private function authorizePricingForOwner(Request $request, string $ownerId): void
    {
        if (! $this->payloadTouchesPricing($request)) {
            return;
        }

        $user = $request->user();

        abort_unless(
            $user->hasRole('super_admin')
                || \App\Services\TenantPermission::for($user, $ownerId)->can('manage_pricing'),
            403,
            'Tài khoản không có quyền đặt giá cho media owner này.'
        );
    }

    private function payloadTouchesPricing(Request $request): bool
    {
        $pricingKeys = [
            'floor_cpm', 'floor_cpm_currency', 'floor_cpm_usd',
            'io_rate', 'io_rate_unit', 'io_kpi_spots_per_day',
            'pricing_model', 'programmatic_enabled', 'pmp_only',
        ];

        $inventory = (array) $request->input('inventory', []);

        return array_intersect_key($inventory, array_flip($pricingKeys)) !== [];
    }

    public function show(Screen $screen): JsonResponse
    {
        Gate::authorize("view", $screen);

        return response()->json(
            $screen->load(['spec', 'inventory', 'site'])->append(['is_online'])
        );
    }

    public function update(Request $request, Screen $screen): JsonResponse
    {
        Gate::authorize("update", $screen);
        $this->authorizePricingPayload($request, $screen);

        $data = $request->validate([
            'name'           => 'sometimes|string|max:199',
            'description'    => 'nullable|string',
            'active'         => 'sometimes|boolean',
            'player_type'    => 'sometimes|in:adtrue_android,adtrue_webview,third_party,vast_only',
            'player_version' => 'nullable|string',
            'spec'           => 'nullable|array',
            'inventory'      => 'nullable|array',
        ]);

        $screen->update(collect($data)->except(['spec', 'inventory'])->toArray());

        if (! empty($data['spec'])) {
            $this->saveSpec($screen, $data['spec']);
        }
        if (! empty($data['inventory'])) {
            $this->saveInventory($screen, $data['inventory']);
        }

        return response()->json($screen->load(['spec', 'inventory']));
    }

    public function destroy(Screen $screen): JsonResponse
    {
        Gate::authorize("delete", $screen);

        $screen->delete();

        return response()->json(null, 204);
    }

    public function multipliers(Screen $screen): JsonResponse
    {
        Gate::authorize("view", $screen);

        return response()->json(
            $screen->multipliers()
                ->orderBy('day_of_week')->orderBy('hour_of_day')
                ->get()->groupBy('day_of_week')
        );
    }

    public function updateMultipliers(Request $request, Screen $screen): JsonResponse
    {
        // Multiplier ảnh hưởng số tiền tính cho người mua ⇒ quyền giá, không phải quyền kho.
        Gate::authorize("managePricing", $screen);

        $data = $request->validate([
            'multipliers'   => 'required|array',
            'multipliers.*.day_of_week' => 'required|integer|min:0|max:6',
            'multipliers.*.hour_of_day' => 'required|integer|min:0|max:23',
            'multipliers.*.multiplier'  => 'required|numeric|min:0',
        ]);

        foreach ($data['multipliers'] as $m) {
            $screen->multipliers()->updateOrCreate(
                ['day_of_week' => $m['day_of_week'], 'hour_of_day' => $m['hour_of_day']],
                ['multiplier' => $m['multiplier']]
            );
        }

        return response()->json(['message' => 'Multipliers updated']);
    }

    public function toggleProgrammatic(Screen $screen): JsonResponse
    {
        Gate::authorize("managePricing", $screen);

        $inv = $screen->inventory;
        if (! $inv) {
            return response()->json(['message' => 'No inventory settings'], 422);
        }

        $inv->update(['programmatic_enabled' => ! $inv->programmatic_enabled]);

        return response()->json(['programmatic_enabled' => $inv->programmatic_enabled]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function saveSpec(Screen $screen, array $data): void
    {
        ScreenSpec::updateOrCreate(
            ['screen_id' => $screen->id],
            array_filter([
                'width_px'    => $data['width_px'] ?? 1920,
                'height_px'   => $data['height_px'] ?? 1080,
                'width_cm'    => $data['width_cm'] ?? null,
                'height_cm'   => $data['height_cm'] ?? null,
                'photo_url'   => $data['photo_url'] ?? null,
                'allow_image' => $data['allow_image'] ?? true,
                'allow_video' => $data['allow_video'] ?? true,
            ], fn ($v) => $v !== null)
        );
    }

    /**
     * Ghi inventory.
     *
     * Tách hẳn "tạo mới" khỏi "vá": trước đây hàm này luôn nhét giá trị mặc định
     * (`programmatic_enabled = false`, `floor_cpm_currency = 'VND'`) vào mọi lần
     * updateOrCreate. Hệ quả: operator chỉ gửi `weekly_impressions` cũng tắt luôn
     * programmatic và reset tiền tệ — vượt qua quyền `manage_pricing` mà không hề
     * chạm tới trường giá nào (Codex follow-up T1, mục 2).
     *
     * Từ nay khi cập nhật chỉ ghi đúng những trường được gửi lên.
     */
    private function saveInventory(Screen $screen, array $data): void
    {
        $allowed = [
            'venue_type', 'vn_category_id', 'floor_cpm', 'floor_cpm_currency',
            'spot_length', 'weekly_impressions', 'programmatic_enabled', 'timezone',
        ];

        // Chỉ lấy trường thực sự có trong payload.
        $payload = array_intersect_key($data, array_flip($allowed));

        $existing = ScreenInventory::where('screen_id', $screen->id)->first();

        if (! $existing) {
            // Tạo mới: bổ sung mặc định cho các trường bắt buộc có giá trị.
            $payload += [
                'floor_cpm_currency'   => 'VND',
                'spot_length'          => 15,
                'programmatic_enabled' => false,
                'timezone'             => 'Asia/Ho_Chi_Minh',
            ];

            ScreenInventory::create(['screen_id' => $screen->id] + $payload);

            return;
        }

        if ($payload !== []) {
            $existing->update($payload);
        }
    }
}
