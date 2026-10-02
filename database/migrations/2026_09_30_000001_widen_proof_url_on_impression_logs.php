<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `proof_url` phải chứa nổi đúng độ dài mà luật kiểm đầu vào cho phép.
 *
 * Luật kiểm nhận tối đa 500 ký tự, còn cột là `varchar(255)`. Với MySQL chế độ
 * nghiêm, một URL dài 256–500 ký tự đi qua được validation rồi làm **phép chèn
 * hỏng** — và đó chính là đường lỗi cụ thể khiến sổ nhận sự kiện có dòng mồ
 * côi trong khi bản ghi chính không tồn tại (Codex R22).
 *
 * Hai con số phải khớp nhau; để lệch là để dành một lỗi cho tương lai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impression_logs') && Schema::hasColumn('impression_logs', 'proof_url')) {
            DB::statement('ALTER TABLE impression_logs MODIFY proof_url VARCHAR(500) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('impression_logs') && Schema::hasColumn('impression_logs', 'proof_url')) {
            DB::statement('ALTER TABLE impression_logs MODIFY proof_url VARCHAR(255) NULL');
        }
    }
};
