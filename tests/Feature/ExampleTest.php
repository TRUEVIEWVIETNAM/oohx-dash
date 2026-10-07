<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Phép kiểm khói: ứng dụng dựng được và phục vụ được một yêu cầu.
     *
     * Đã đổi đích hai lần: `/` → `/agency` → `/api/v2/stats`. Giai đoạn 7 xong
     * (07/10/2026) nên Laravel không còn phục vụ trang HTML công khai nào —
     * điểm vào công khai duy nhất còn lại là API.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->getJson('/api/v2/stats')->assertStatus(200);
    }
}
