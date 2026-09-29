<?php

namespace App\Console\Commands;

use App\Models\Screen;
use App\Services\Player\DeviceAuthenticator;
use Illuminate\Console\Command;

/**
 * Cấp token cho một thiết bị phát.
 *
 * Bản rõ chỉ hiện **một lần**, ở đây. Trong CSDL chỉ có bản băm, nên mất token
 * thì cấp lại chứ không đọc lại được — đúng như mật khẩu.
 */
class IssueDeviceToken extends Command
{
    protected $signature = 'screens:issue-device-token {screen : UUID hoặc external_id của màn hình}';

    protected $description = 'Cấp token mới cho thiết bị phát của một màn hình';

    public function handle(DeviceAuthenticator $devices): int
    {
        $key = (string) $this->argument('screen');

        $screen = Screen::withoutGlobalScopes()
            ->where('uuid', $key)
            ->orWhere('external_id', $key)
            ->first();

        if (! $screen) {
            $this->error("Không tìm thấy màn hình: {$key}");

            return self::FAILURE;
        }

        if ($screen->device_token && ! $this->confirm("Màn hình \"{$screen->name}\" đã có token. Cấp token mới sẽ làm thiết bị đang chạy ngừng gửi được. Tiếp tục?")) {
            return self::FAILURE;
        }

        $plain = $devices->issueToken($screen);

        $this->info("Màn hình: {$screen->name} ({$screen->uuid})");
        $this->newLine();
        $this->line('Token (chỉ hiện lần này, lưu lại ngay):');
        $this->line($plain);
        $this->newLine();
        $this->comment('Thiết bị gửi kèm header:  X-Device-Token: <token>');

        return self::SUCCESS;
    }
}
