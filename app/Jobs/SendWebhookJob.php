<?php

namespace App\Jobs;

use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 15;

    private readonly string $eventId;
    private readonly string $occurredAt;

    public function __construct(
        private readonly WebhookSubscription $subscription,
        private readonly string $event,
        private readonly array $data,
    ) {
        $this->onQueue('webhooks');
        // Cố định ngay khi tạo job: retry phải gửi lại đúng payload cũ, không đổi
        // mã sự kiện lẫn thời điểm.
        $this->eventId   = (string) \Illuminate\Support\Str::ulid();
        $this->occurredAt = now()->toIso8601String();
    }

    public function handle(): void
    {
        $payload = [
            // Mã sự kiện ổn định qua mọi lần thử lại, để bên nhận chống trùng.
            // Sinh trong constructor, không sinh lại mỗi attempt (Codex R10).
            'event_id'  => $this->eventId,
            'event'     => $this->event,
            'timestamp' => $this->occurredAt,
            'data'      => $this->data,
        ];

        // Kiểm lại đích ngay trước khi gửi: bản ghi DNS có thể đã đổi kể từ lúc
        // đăng ký, trỏ về mạng nội bộ (Codex R10).
        [$status, $reason] = \App\Rules\SafePublicUrl::inspect($this->subscription->url);

        if ($status === \App\Rules\SafePublicUrl::UNSAFE) {
            // Đích chắc chắn không được phép: tắt hẳn, không thử lại.
            Log::error('Webhook destination bị chặn', [
                'webhook_id' => $this->subscription->webhook_id,
                'reason'     => $reason,
            ]);
            $this->subscription->update(['status' => 'inactive']);

            return;
        }

        if ($status === \App\Rules\SafePublicUrl::UNRESOLVED) {
            // DNS trục trặc là sự cố hạ tầng, không phải hành vi xấu — thử lại,
            // không tắt subscription của đối tác.
            throw new RuntimeException($reason ?? 'Không phân giải được tên miền webhook.');
        }

        $body      = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $this->subscription->secret);

        $response = Http::timeout(10)
            // Không đi theo chuyển hướng: đích công cộng có thể 302 về mạng nội bộ.
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Type'       => 'application/json',
                'X-TapOn-Signature'  => $signature,
                'X-TapOn-Event'      => $this->event,
            ])
            ->send('POST', $this->subscription->url, ['body' => $body]);

        if (! $response->successful()) {
            Log::warning('Webhook delivery failed', [
                'webhook_id' => $this->subscription->webhook_id,
                'url'        => $this->subscription->url,
                'event'      => $this->event,
                'event_id'   => $this->eventId,
                'attempt'    => $this->attempts(),
                'status'     => $response->status(),
            ]);

            // Ném ngoại lệ để hàng đợi thử lại theo $tries và backoff().
            // Trước đây gọi $this->fail() — lệnh đó đánh dấu job hỏng NGAY, nên
            // backoff() là code chết và nhánh "sau 3 lần thì tắt subscription"
            // không bao giờ chạy tới (Codex F18 phát hiện).
            throw new RuntimeException(
                "Webhook {$this->subscription->webhook_id} trả về HTTP {$response->status()}"
            );
        }
    }

    /**
     * Chỉ chạy khi đã hết số lần thử. Đây mới là chỗ đúng để tắt subscription.
     */
    public function failed(?Throwable $exception): void
    {
        $this->subscription->update(['status' => 'inactive']);

        Log::error('Webhook subscription bị tắt sau khi hết số lần thử', [
            'webhook_id' => $this->subscription->webhook_id,
            'event_id'   => $this->eventId,
            'error'      => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [30, 300, 1800]; // 30s, 5m, 30m
    }
}
