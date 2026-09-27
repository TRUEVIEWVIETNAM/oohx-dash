<?php
// Audit-only probes. These characterize defects, not passing acceptance tests.
// Uses synthetic in-memory models and a minimal SQLite fixture, never application data.
require '/source/vendor/autoload.php';
$app = require '/tmp/oohx-audit/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Services\CartService;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

$results = [];
$screen = new Screen;
$screen->setRelation('inventory', new ScreenInventory([
    'pricing_model' => 'io', 'io_rate' => 1000000, 'io_rate_unit' => 'month',
    'weekly_impressions' => 70000,
]));
$service = new CartService;
$base = ['start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'screen_count' => 1];
$derived = $service->estimateCost($screen, $base);
$overridden = $service->estimateCost($screen, $base + ['duration_units' => 1]);
$results['F04_client_duration_override'] = [
    'same_dates' => $base,
    'derived_cost' => $derived['cost'], 'submitted_duration_1_cost' => $overridden['cost'],
    'defect_reproduced' => $overridden['cost'] < $derived['cost'],
];

// Minimal fixture only for getBookedSOV. It does not certify production migrations or concurrency.
Schema::create('booking_lines', function ($t) {
    $t->id(); $t->string('screen_id'); $t->string('status');
    $t->date('start_date'); $t->date('end_date'); $t->integer('share_of_voice_pct');
});
DB::table('booking_lines')->insert([
    ['screen_id' => 'audit-screen', 'status' => 'approved', 'start_date' => '2027-01-01', 'end_date' => '2027-01-15', 'share_of_voice_pct' => 50],
    ['screen_id' => 'audit-screen', 'status' => 'approved', 'start_date' => '2027-01-16', 'end_date' => '2027-01-31', 'share_of_voice_pct' => 50],
]);
$sov = (new AvailabilityService)->getBookedSOV('audit-screen', '2027-01-01', '2027-01-31');
$results['F05_disjoint_intervals'] = [
    'daily_peak_sov' => 50, 'service_sum_sov' => $sov,
    'defect_reproduced' => $sov === 100,
];

// Invoke the real controller validation; valid ULID is rejected before screen lookup.
$request = Illuminate\Http\Request::create('/api/v1/player/impression', 'POST', [
    'screen_uuid' => '00000000-0000-4000-8000-000000000001',
    'campaign_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'creative_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'duration_sec' => 15,
]);
try {
    (new App\Http\Controllers\Api\V1\PlayerController)->impression($request);
    $results['F08_player_ulid'] = ['defect_reproduced' => false];
} catch (Illuminate\Validation\ValidationException $e) {
    $results['F08_player_ulid'] = [
        'validation_errors' => array_keys($e->errors()),
        'defect_reproduced' => isset($e->errors()['campaign_id'], $e->errors()['creative_id']),
    ];
}

echo json_encode([
    'fixture' => 'Synthetic models; minimal SQLite booking_lines only; not the full application schema.',
    'probes' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
foreach ($results as $result) {
    if (!$result['defect_reproduced']) exit(1);
}
