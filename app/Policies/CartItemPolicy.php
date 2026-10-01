<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

/**
 * Quyền trên một dòng giỏ hàng.
 *
 * Trước policy này, `CartController` so bằng tay
 * `$item->cart->user_id === $request->user()->id` ở hai chỗ. Giữ nguyên **ý
 * nghĩa** đó — giỏ là của riêng từng người, không chia sẻ trong tổ chức — và
 * chuyển nó thành một chỗ duy nhất, vì endpoint v2 sắp cần đúng luật ấy và
 * viết lại bằng tay lần thứ ba là cách để ba chỗ trôi khỏi nhau.
 *
 * Vì sao theo **người** chứ không theo **tổ chức**: giỏ là bản nháp đang soạn,
 * không phải đơn hàng. Hai người cùng tổ chức sửa chung một bản nháp sẽ ghi
 * đè lẫn nhau mà không ai thấy. Đơn hàng thật (`Campaign`) mới là thứ cả tổ
 * chức nhìn chung, và nó có `CampaignPolicy` riêng.
 */
class CartItemPolicy
{
    public function view(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function update(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    private function owns(User $user, CartItem $item): bool
    {
        return $item->cart?->user_id === $user->id;
    }
}
