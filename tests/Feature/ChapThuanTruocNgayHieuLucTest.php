<?php

namespace Tests\Feature;

use App\Models\PolicyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `policies:consents-before-effective` — ai đã đồng ý một bản chính sách
 * **trước** ngày bản đó có hiệu lực.
 *
 * ══ Câu hỏi lệnh này trả lời ══
 *
 * Ba trang chính sách chỉ ban hành 09/10/2026, còn `PolicyConsentService` đã
 * đóng dấu `version` lên từng bản ghi chấp thuận từ trước đó. Nên có khả năng
 * một người dùng đã bấm đồng ý với bản `1.0` khi bản ấy còn là **bản nháp**.
 * Với hồ sơ TMĐT, "ai đồng ý phiên bản nào, lúc nào" là câu phải trả lời được.
 *
 * Lệnh chỉ **đọc**: nó không gửi thư và không đòi lại chấp thuận. Làm gì với
 * danh sách là một quyết định pháp lý.
 *
 * ══ Ca đáng giá nhất là ca ngày tháng ══
 *
 * `effective_from` viết `dd/mm/yyyy`. `Carbon::parse('09/10/2026')` đọc chuỗi
 * đó theo kiểu Mỹ và trả ra **10/09/2026** — lệch một tháng, và lệch về phía
 * làm lệnh **báo thiếu người**. Một lỗi báo thiếu trong một phép đếm dùng để
 * trả lời cơ quan quản lý thì tệ hơn hẳn một lỗi báo thừa.
 */
class ChapThuanTruocNgayHieuLucTest extends TestCase
{
    use RefreshDatabase;

    private function chapThuan(User $user, string $key, string $version, Carbon $luc): PolicyConsent
    {
        return PolicyConsent::create([
            'user_id'        => $user->id,
            'policy_key'     => $key,
            'policy_version' => $version,
            'context'        => PolicyConsent::CONTEXT_REGISTER,
            'subject_id'     => null,
            'consented_at'   => $luc,
            'ip'             => '127.0.0.1',
            'user_agent'     => 'phpunit',
        ]);
    }

    /** Một cấu hình chính sách tối thiểu, ban hành ngày cho sẵn. */
    private function dungConfig(string $hieuLuc = '09/10/2026', string $version = '1.0'): void
    {
        config()->set('policies.pages', [
            'quy-che-hoat-dong' => [
                'key'            => 'terms',
                'title'          => 'Quy chế hoạt động',
                'body'           => 'frontpage.policies.bodies.terms',
                'version'        => $version,
                'effective_from' => $hieuLuc,
            ],
        ]);
    }

    public function test_khong_co_chap_thuan_nao_som_thi_bao_khong_co(): void
    {
        $this->dungConfig();
        $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-10-11 09:00'));

        $this->artisan('policies:consents-before-effective')
            ->expectsOutputToContain('Không có lần chấp thuận nào xảy ra trước ngày hiệu lực.')
            ->assertExitCode(0);
    }

    public function test_dem_dung_so_nguoi_dong_y_truoc_ngay_ban_hanh(): void
    {
        $this->dungConfig();

        // Hai người đồng ý khi bản còn là bản nháp.
        $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-10-01 10:00'));
        $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-10-08 23:59'));

        // Một người đồng ý đúng ngày ban hành — KHÔNG tính: ban hành tính theo
        // ngày, nên 09/10 lúc 00:00 trở đi là đã có hiệu lực.
        $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-10-09 08:00'));

        // Một người đồng ý bản KHÁC — không liên quan.
        $this->chapThuan(User::factory()->create(), 'terms', '0.9', Carbon::parse('2026-10-01 10:00'));

        $this->artisan('policies:consents-before-effective')
            ->expectsOutputToContain('Tổng 2 lần chấp thuận')
            ->assertExitCode(0);
    }

    /**
     * Ngày hiệu lực đọc theo `d/m/Y`, không theo kiểu Mỹ.
     *
     * `03/12/2026` là **3 tháng 12**. Đọc kiểu Mỹ thì nó thành 12 tháng 3, và
     * một chấp thuận ngày 01/11/2026 — rõ ràng *trước* ngày ban hành — sẽ bị
     * đếm thành *sau*, tức lệnh báo 0 người.
     */
    public function test_doc_ngay_theo_dd_mm_yyyy_khong_theo_kieu_my(): void
    {
        $this->dungConfig(hieuLuc: '03/12/2026');

        $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-11-01 10:00'));

        $this->artisan('policies:consents-before-effective')
            ->expectsOutputToContain('Tổng 1 lần chấp thuận')
            ->assertExitCode(0);
    }

    /**
     * Trang chưa ban hành: **mọi** chấp thuận của nó là chấp thuận với bản nháp.
     *
     * `effective_from` rỗng là một trạng thái được hỗ trợ — `PublicContentController`
     * phát `is_effective: false` cho chính trường hợp này.
     */
    public function test_trang_chua_ban_hanh_thi_moi_chap_thuan_deu_tinh(): void
    {
        config()->set('policies.pages', [
            'chinh-sach-moi' => [
                'key'            => 'terms',
                'title'          => 'Bản nháp',
                'body'           => 'frontpage.policies.bodies.terms',
                'version'        => '2.0',
                'effective_from' => null,
            ],
        ]);

        $this->chapThuan(User::factory()->create(), 'terms', '2.0', Carbon::parse('2026-10-01 10:00'));
        $this->chapThuan(User::factory()->create(), 'terms', '2.0', Carbon::parse('2026-12-31 10:00'));

        $this->artisan('policies:consents-before-effective')
            ->expectsOutputToContain('CHƯA BAN HÀNH')
            ->expectsOutputToContain('Tổng 2 lần chấp thuận')
            ->assertExitCode(0);
    }

    public function test_chi_tiet_liet_ke_email_tung_nguoi(): void
    {
        $this->dungConfig();

        $user = User::factory()->create(['email' => 'nguoi.dong.y@example.test']);
        $this->chapThuan($user, 'terms', '1.0', Carbon::parse('2026-10-01 10:00'));

        $this->artisan('policies:consents-before-effective --chi-tiet')
            ->expectsOutputToContain('nguoi.dong.y@example.test')
            ->assertExitCode(0);
    }

    /**
     * Lệnh KHÔNG được sửa gì.
     *
     * Nó tồn tại để đếm. Một lệnh chẩn đoán mà ghi vào bảng chấp thuận là một
     * lệnh làm hỏng đúng thứ bằng chứng nó đang kiểm.
     */
    public function test_lenh_khong_sua_ban_ghi_nao(): void
    {
        $this->dungConfig();

        $c = $this->chapThuan(User::factory()->create(), 'terms', '1.0', Carbon::parse('2026-10-01 10:00'));

        // `ksort` hai phía: thứ tự khóa của một model vừa `create()` khác thứ
        // tự của cùng model đọc lại từ CSDL, và ca này nói về **giá trị**.
        $truoc = $c->toArray();
        ksort($truoc);

        $this->artisan('policies:consents-before-effective --chi-tiet')->assertExitCode(0);

        $sau = $c->fresh()->toArray();
        ksort($sau);

        $this->assertSame($truoc, $sau);
        $this->assertSame(1, PolicyConsent::count());
    }
}
