<?php

namespace Tests\Feature\Api\V2;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `GET /api/v2/agencies` — agency và brand trên trang công khai.
 *
 * ══ Lớp này canh một thứ khác hẳn các endpoint danh mục khác ══
 *
 * Nguồn là `Organization`, bảng của bên **MUA**. `/screens` và `/owners` phơi
 * dữ liệu của bên bán, và bên bán công khai kho hàng để được tìm thấy. Ở đây
 * thì không: một agency không đăng ký để điều khoản thanh toán của họ nằm
 * trên trang ai cũng mở được.
 *
 * Nên phần lớn lớp này là phép kiểm **không lộ**, và chúng liệt kê tên trường
 * cụ thể chứ không chỉ đếm khoá — một DTO mới thêm trường nhạy cảm sẽ không
 * làm đổi số lượng khoá theo cách ai đó để ý.
 */
class CatalogAgencyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function agency(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'name'    => 'Agency Một',
            'slug'    => 'agency-mot-' . substr(uniqid(), -5),
            'type'    => 'agency',
            'status'  => 'active',
            'website' => 'https://agency-mot.vn/trang-chu?utm_source=oohx',
        ], $attributes));
    }

    public function test_tra_agency_dang_hoat_dong(): void
    {
        $this->agency(['name' => 'Agency Đang Chạy']);

        $this->getJson('/api/v2/agencies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Agency Đang Chạy')
            ->assertJsonPath('data.0.type', 'agency')
            ->assertJsonPath('meta.max_per_page', 50);
    }

    public function test_khong_tra_to_chuc_khong_phai_agency(): void
    {
        $this->agency(['name' => 'Agency Thật']);
        $this->agency(['name' => 'Bên Mua Khác', 'type' => 'client']);

        $ten = collect($this->getJson('/api/v2/agencies')->assertOk()->json('data'))
            ->pluck('name')->all();

        $this->assertContains('Agency Thật', $ten);
        $this->assertNotContains('Bên Mua Khác', $ten);
    }

    public function test_khong_tra_to_chuc_bi_dinh_chi(): void
    {
        // enum `status` của bảng `organizations` chỉ có `active` và
        // `suspended`. Bản đầu tôi viết `inactive` và MySQL trả "Data
        // truncated for column 'status'" — một thông báo không nói ra rằng
        // giá trị đó không tồn tại trong enum.
        $this->agency(['name' => 'Bị Đình Chỉ', 'status' => 'suspended']);

        $this->assertSame([], $this->getJson('/api/v2/agencies')->assertOk()->json('data'));
    }

    // ── Không lộ dữ liệu của bên mua ────────────────────────────────────────

    public function test_khong_bao_gio_lo_truong_nhay_cam(): void
    {
        // Tên cột lấy từ migration, không đoán. Bản đầu tôi viết `tax_code` —
        // tên ở bảng `owners` — và MySQL trả "Unknown column". Bảng
        // `organizations` dùng `tax_id`, và nó còn mang `credit_limit`:
        // HẠN MỨC TÍN DỤNG của bên mua, thứ nặng hơn hẳn mọi trường tôi đoán.
        $org = $this->agency();
        $org->forceFill([
            'tax_id'             => '0101010101',
            'billing_address'    => 'Số 1 Đường Kín',
            'billing_email'      => 'ke-toan-rieng@example.com',
            'billing_phone'      => '0900000009',
            'credit_limit'       => 500000000,
            'payment_terms_days' => 45,
        ])->saveQuietly();

        $than = $this->getJson('/api/v2/agencies')->assertOk()->getContent();

        // Liệt kê TÊN TRƯỜNG **và** GIÁ TRỊ. Chỉ canh tên thì một DTO đổi tên
        // khoá mà vẫn trả giá trị sẽ lọt; chỉ canh giá trị thì một khoá rỗng
        // nhưng có tên vẫn nói cho người đọc biết sàn lưu gì về khách hàng.
        foreach ([
            'tax_id', '0101010101',
            'billing_address', 'Số 1 Đường Kín',
            'billing_email', 'ke-toan-rieng@example.com',
            'billing_phone', '0900000009',
            'credit_limit', '500000000',
            'payment_terms_days', '45',
            'slug',
        ] as $cam) {
            $this->assertStringNotContainsString(
                $cam,
                $than,
                "Danh sách agency để lộ \"{$cam}\" — đây là dữ liệu của bên MUA.",
            );
        }
    }

    public function test_website_chi_tra_ten_mien_khong_tra_tham_so_theo_doi(): void
    {
        $this->agency();

        $this->getJson('/api/v2/agencies')
            ->assertOk()
            ->assertJsonPath('data.0.website_host', 'agency-mot.vn');

        // URL đầy đủ có thể mang tham số theo dõi mà agency không định công
        // khai. Ở đây nó là `?utm_source=oohx`, do chính sàn gắn.
        $this->assertStringNotContainsString(
            'utm_source',
            $this->getJson('/api/v2/agencies')->getContent(),
        );
    }

    public function test_khong_co_website_thi_tra_null_khong_tra_chuoi_rong(): void
    {
        $this->agency(['website' => null]);

        $this->getJson('/api/v2/agencies')
            ->assertOk()
            ->assertJsonPath('data.0.website_host', null);
    }

    // ── Số liệu phải thật ───────────────────────────────────────────────────

    public function test_dem_chien_dich_la_so_that_khong_phai_khong_mac_dinh(): void
    {
        $this->agency();

        // Chưa có chiến dịch nào thì phải là 0 — đếm rồi, không có cái nào.
        // KHÁC null, nghĩa là không đếm. Thiếu `withCount` trong service thì
        // trường này về null và bên hiển thị sẽ in `—` cho một agency có
        // chiến dịch thật.
        $this->getJson('/api/v2/agencies')
            ->assertOk()
            ->assertJsonPath('data.0.campaign_count', 0);
    }

    // ── Lọc và phân trang ───────────────────────────────────────────────────

    public function test_loc_theo_ten(): void
    {
        $this->agency(['name' => 'Quảng Cáo Phương Nam']);
        $this->agency(['name' => 'Truyền Thông Bắc Hà']);

        $ten = collect($this->getJson('/api/v2/agencies?q=Phương Nam')->assertOk()->json('data'))
            ->pluck('name')->all();

        $this->assertSame(['Quảng Cáo Phương Nam'], $ten);
    }

    public function test_phan_trang_co_gioi_han_cung(): void
    {
        $this->agency();

        $this->getJson('/api/v2/agencies?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_tu_choi_q_dang_mang_thay_vi_tra_500(): void
    {
        // Cùng lớp lỗi R40: một mảng gửi vào chỗ chờ chuỗi phải ra 422 nói rõ
        // sai ở đâu, không phải 500.
        $this->getJson('/api/v2/agencies?q[]=x')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }
}
