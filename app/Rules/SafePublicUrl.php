<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL do người ngoài cung cấp (webhook) phải trỏ ra Internet công cộng.
 *
 * Không có luật này, đối tác đăng ký callback trỏ vào mạng nội bộ của máy chủ và
 * biến hệ thống thành công cụ dò quét bên trong — kể cả endpoint metadata của nhà
 * cung cấp đám mây (169.254.169.254) thường chứa thông tin đăng nhập tạm thời.
 *
 * Đây là lớp chặn lúc ĐĂNG KÝ. Phải kiểm lại lần nữa ngay trước mỗi lần gửi, vì
 * bản ghi DNS có thể đổi sau khi đăng ký (Codex R10). Xem SendWebhookJob.
 */
class SafePublicUrl implements ValidationRule
{
    /** Đích chắc chắn không được phép — tắt subscription. */
    public const UNSAFE = 'unsafe';

    /** Không phân giải được tên miền — lỗi tạm thời, phải thử lại. */
    public const UNRESOLVED = 'unresolved';

    public const OK = 'ok';

    /** Cho test thay bộ phân giải DNS; production luôn dùng DNS thật. */
    private static ?Closure $resolver = null;

    public static function fakeResolver(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reason = static::rejectionReason((string) $value);

        if ($reason !== null) {
            $fail($reason);
        }
    }

    /**
     * Phân loại đích trước khi gửi.
     *
     * Phân biệt "chắc chắn cấm" với "tạm thời chưa phân giải được" là quan trọng:
     * gộp chung thì một trục trặc DNS thoáng qua sẽ tắt vĩnh viễn webhook của đối
     * tác, mà đó là sự cố hạ tầng chứ không phải hành vi xấu.
     *
     * @return array{0: string, 1: ?string} [trạng thái, lý do]
     */
    public static function inspect(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return [static::UNSAFE, 'Địa chỉ webhook không hợp lệ.'];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return [static::UNSAFE, 'Địa chỉ webhook chỉ chấp nhận http hoặc https.'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return [static::UNSAFE, 'Địa chỉ webhook không được chứa thông tin đăng nhập.'];
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443, 8080, 8443], true)) {
            return [static::UNSAFE, 'Cổng của địa chỉ webhook không được chấp nhận.'];
        }

        $ips = static::resolveAll($parts['host']);

        if ($ips === []) {
            return [static::UNRESOLVED, 'Không phân giải được tên miền của webhook.'];
        }

        foreach ($ips as $ip) {
            if (static::isForbiddenIp($ip)) {
                return [static::UNSAFE, 'Địa chỉ webhook trỏ tới vùng mạng nội bộ, không được chấp nhận.'];
            }
        }

        return [static::OK, null];
    }

    /**
     * Trả về lý do từ chối, hoặc null nếu URL dùng được.
     * Tách ra static để job gửi webhook gọi lại được trước mỗi lần gửi.
     */
    /**
     * Lý do từ chối lúc ĐĂNG KÝ. Ở bước này, tên miền không phân giải được cũng
     * bị từ chối — đăng ký một địa chỉ không tồn tại là chuyện bất thường.
     */
    public static function rejectionReason(string $url): ?string
    {
        [$status, $reason] = static::inspect($url);

        return $status === static::OK ? null : $reason;
    }

    /**
     * Mọi địa chỉ IPv4 và IPv6 mà tên miền phân giải ra.
     * Không phân giải được thì coi như không dùng được — thà chặn nhầm còn hơn gửi bừa.
     *
     * @return string[]
     */
    public static function resolveAll(string $host): array
    {
        // parse_url trả host IPv6 kèm ngoặc vuông: "[::1]". Không bóc ra thì
        // filter_var không nhận ra đó là địa chỉ IP, và một địa chỉ loopback IPv6
        // lọt thẳng qua vòng kiểm.
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (static::$resolver !== null) {
            return (array) (static::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];

        $ips = [];
        foreach ($records as $record) {
            if (! empty($record['ip']))   $ips[] = $record['ip'];
            if (! empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }

        // Rỗng nghĩa là chưa phân giải được — nhánh gọi tự quyết định coi đó là
        // lỗi tạm thời hay từ chối hẳn.
        return $ips;
    }

    /**
     * Loopback, mạng riêng, link-local (gồm 169.254.169.254), và IPv6 tương đương
     * kể cả dạng ánh xạ ::ffff:10.0.0.1.
     */
    public static function isForbiddenIp(string $ip): bool
    {
        // Gỡ lớp ánh xạ IPv4-trong-IPv6 trước khi kiểm.
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $mapped;
            }
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE loại mạng riêng và dải dành riêng
        // (gồm loopback, link-local, multicast).
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
