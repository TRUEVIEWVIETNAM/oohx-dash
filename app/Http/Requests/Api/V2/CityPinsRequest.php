<?php

namespace App\Http\Requests\Api\V2;

use App\Services\FrontpageService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tham số cho `GET /api/v2/screens/pins` — pin bản đồ của MỘT thành phố.
 *
 * ══ `city` bắt buộc, cùng lý lẽ với khung nhìn ở `/screens/map` ══
 *
 * `MapViewportRequest` bắt buộc có khung nhìn vì thiếu nó thì "lấy pin bản đồ"
 * nghĩa là lấy mọi màn hình có toạ độ — một lần xuất toàn bộ kho dưới một cái
 * tên vô hại (CLAUDE.md mục 2). Endpoint này dùng **thành phố** làm phạm vi
 * thay cho khung nhìn, nên `city` cũng phải bắt buộc.
 *
 * ══ Và phải kiểm theo danh sách THẬT, không chỉ `required` ══
 *
 * Đây là chỗ dễ bỏ sót nhất. `FrontpageService::resolveCityName()` trả `null`
 * cho slug không nhận ra, và `getCityMapPins()` khi đó **không áp filter thành
 * phố nào** — tức `?city=xyz` cho ra pin của cả nước. `required` một mình
 * không đóng được cửa đó; phải là `in:` theo đúng danh sách
 * `FrontpageService::citySlugs()`.
 *
 * Lấy danh sách từ service chứ không viết lại ở đây: hai danh sách sẽ trôi
 * khỏi nhau, và khi trôi thì cái thiếu là một slug lọt qua phép kiểm rồi rơi
 * vào nhánh không-filter.
 */
class CityPinsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city'  => ['required', 'string', 'in:' . implode(',', FrontpageService::citySlugs())],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'city.required' => 'Thiếu tham số city. Endpoint này trả pin của một thành phố, không trả cả nước.',
            'city.in'       => 'Giá trị city không nhận ra. Dùng một trong: ' . implode(', ', FrontpageService::citySlugs()) . '.',
        ];
    }

    public function citySlug(): string
    {
        return (string) $this->validated()['city'];
    }
}
