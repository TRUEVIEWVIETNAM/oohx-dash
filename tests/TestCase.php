<?php

namespace Tests;

use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chạy một thay đổi dữ liệu **không nhân danh ai**.
     *
     * Dùng cho fixture: nhiều test cần đổi giá kho để dựng tình huống ("owner
     * đổi giá trong lúc giỏ còn nằm đó") trong khi đang đóng vai người mua.
     * Từ khi có cổng quyền giá ở `ScreenInventoryObserver`, thao tác đó bị chặn
     * 403 — đúng như nó phải chặn trong đời thật, vì người mua không được sửa
     * giá của media owner.
     *
     * Bọc bằng hàm này nói rõ ý định: đây là dàn cảnh ở tầng dữ liệu, không
     * phải hành vi của người dùng đang đăng nhập. Không dùng nó để lách một
     * phép kiểm quyền mà test đang muốn kiểm.
     */
    protected function asDataFixture(Closure $callback): mixed
    {
        $previous = auth()->user();

        auth()->forgetUser();

        try {
            return $callback();
        } finally {
            if ($previous) {
                auth()->setUser($previous);
            }
        }
    }
}
