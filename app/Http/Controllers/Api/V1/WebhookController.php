<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url'    => ['required', 'url', 'max:500', new \App\Rules\SafePublicUrl()],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(['screen.created', 'screen.updated', 'screen.deactivated'])],
            'secret' => ['required', 'string', 'min:16'],
        ]);

        /** @var \App\Models\ApiClient $client */
        $client = $request->user();

        $subscription = WebhookSubscription::updateOrCreate(
            [
                'api_client_id' => $client->id,
                'url'           => $validated['url'],
            ],
            [
                'webhook_id' => 'WH-' . strtoupper(Str::random(6)),
                'events'     => $validated['events'],
                'secret'     => $validated['secret'],
                'status'     => 'active',
            ]
        );

        return response()->json([
            'webhook_id' => $subscription->webhook_id,
            'status'     => $subscription->status,
        ]);
    }
}
