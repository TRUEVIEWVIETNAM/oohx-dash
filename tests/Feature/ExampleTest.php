<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Phép kiểm khói: ứng dụng dựng được và phục vụ được một trang.
     *
     * Trước đây gọi `/`. Giai đoạn 7 (07/10/2026) chuyển trang chủ sang Next,
     * nên Laravel không còn route cho nó — `/agency` là trang công khai duy
     * nhất Laravel còn phục vụ.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('http://' . config('domains.frontpage', 'oohx.net') . '/agency');

        $response->assertStatus(200);
    }
}
