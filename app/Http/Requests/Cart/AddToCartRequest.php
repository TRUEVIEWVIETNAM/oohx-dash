<?php

namespace App\Http\Requests\Cart;

use App\Models\Product;
use App\Models\Screen;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Luật kiểm cho việc thêm vào giỏ — **dùng chung** trang Blade và `/api/v2`.
 *
 * Trước file này, luật nằm thẳng trong `CartController::add()`. Viết lại một
 * bộ thứ hai cho API là đúng cái CLAUDE.md mục 4 cấm: "quyền phải được định
 * nghĩa một chỗ... không viết hai bộ luật". Luật kiểm cũng vậy — hai bộ thì
 * một ngày nào đó API nhận thứ mà trang từ chối, hoặc ngược lại, và không ai
 * biết bên nào đúng.
 *
 * **Hai cách gọi tên, một bộ ràng buộc.** Trang Blade gửi `screen_id` /
 * `product_id` (khóa nội bộ, vì nó dựng form từ model sẵn trong tay). API
 * không được bắt client đoán khóa nội bộ nên nhận `screen_slug` /
 * `product_slug` và quy đổi ở đây. Ràng buộc thật thì vẫn đúng một bộ.
 */
class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ai được thêm vào giỏ nào thì do controller quyết (giỏ của chính
        // người đang đăng nhập). Ở đây chỉ kiểm hình dạng dữ liệu.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->filled('screen_id') && $this->filled('screen_slug')) {
            $merge['screen_id'] = Screen::publiclyVisible()
                ->where('slug', $this->input('screen_slug'))
                ->value('id');
        }

        if (! $this->filled('product_id') && $this->filled('product_slug')) {
            $merge['product_id'] = Product::publiclyVisible()
                ->where('slug', $this->input('product_slug'))
                ->value('id');
        }

        if ($this->filled('selected_screen_slugs') && ! $this->filled('selected_screen_ids')) {
            $slugs = (array) $this->input('selected_screen_slugs');

            $merge['selected_screen_ids'] = Screen::publiclyVisible()
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->all();
        }

        // Quy đổi hỏng (slug không tồn tại, hoặc màn hình không công khai) thì
        // để `null` rơi xuống luật `required`/`exists` bên dưới và trả 422 có
        // tên trường — không im lặng bỏ qua.
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        // Khoảng ngày tối đa nằm ở config để nhìn thấy được, không viết cứng.
        $maxRange = 'before_or_equal:' . now()->addDays((int) config('pricing.max_range_days', 365))->toDateString();

        if ($this->buysProduct()) {
            return [
                'product_id'            => ['required', 'string', 'exists:products,id'],
                'buy_mode'              => ['nullable', 'in:package,individual'],
                'selected_screen_ids'   => ['nullable', 'array'],
                'selected_screen_ids.*' => ['string', 'exists:screens,id'],
                'start_date'            => ['nullable', 'date', 'after_or_equal:today'],
                'end_date'              => ['nullable', 'date', 'after:start_date'],
            ];
        }

        return [
            'screen_id'      => ['required', 'string', 'exists:screens,id'],
            'product_id'     => ['nullable', 'string', 'exists:products,id'],
            'start_date'     => ['required', 'date', 'after_or_equal:today'],
            'end_date'       => ['required', 'date', 'after_or_equal:start_date', $maxRange],
            'quantity'       => ['nullable', 'integer', 'min:1'],
            'pricing_model'  => ['nullable', 'in:cpm,io'],
            // Hai trường dưới chỉ là "mua thêm": máy chủ tự suy mức tối thiểu
            // từ ngày, gửi thấp hơn sẽ bị từ chối chứ không bị âm thầm nâng.
            'booked_cpms'    => ['nullable', 'integer', 'min:1'],
            'duration_units' => ['nullable', 'integer', 'min:1'],
            // `screen_count` đã bỏ: một dòng giỏ ứng với một màn hình, số
            // thiết bị lấy từ cấu hình kho chứ không nhận từ client.
        ];
    }

    /** Thêm theo sản phẩm hay theo màn hình lẻ. */
    public function buysProduct(): bool
    {
        return ($this->filled('product_id') || $this->filled('product_slug'))
            && ! $this->filled('screen_id')
            && ! $this->filled('screen_slug');
    }
}
