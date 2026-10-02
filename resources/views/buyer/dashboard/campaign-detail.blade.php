@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', $campaign->name . ' | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    @if(session('success'))
    <div class="cart-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
    <div class="cart-alert" style="border-color:var(--red);color:var(--red)">{{ session('error') }}</div>
    @endif

    <div class="buyer-welcome">
        <div>
            <div style="font-size:12px;color:var(--t4);font-weight:600;margin-bottom:4px">{{ $campaign->code }}</div>
            <h1 class="buyer-welcome-title">{{ $campaign->name }}</h1>
            <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                @php
                    $statusColors = ['draft'=>'b-gray','pending_approval'=>'b-org','approved'=>'b-bl','rejected'=>'b-red','active'=>'b-grn','paused'=>'b-org','completed'=>'b-gray','cancelled'=>'b-red'];
                    $statusLabels = ['draft'=>'Nháp','pending_approval'=>'Chờ duyệt','approved'=>'Đã duyệt','rejected'=>'Từ chối','active'=>'Đang chạy','paused'=>'Tạm dừng','completed'=>'Hoàn thành','cancelled'=>'Đã hủy'];
                @endphp
                <span class="badge {{ $statusColors[$campaign->status] ?? 'b-gray' }}">{{ $statusLabels[$campaign->status] ?? $campaign->status }}</span>
                <span style="font-size:13px;color:var(--t3)">{{ $campaign->start_date->format('d/m/Y') }} → {{ $campaign->end_date->format('d/m/Y') }}</span>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            @if(in_array($campaign->status, ['active', 'completed', 'paused']))
            <a href="{{ route('buyer.campaigns.report', $campaign) }}" class="btn btn-p btn-sm">Xem báo cáo</a>
            @endif
            @if($campaign->status === 'approved')
            <a href="{{ route('buyer.payment', $campaign) }}" class="btn btn-p btn-sm">Thanh toán</a>
            @endif
            <a href="{{ route('buyer.campaigns') }}" class="btn btn-s btn-sm">Tất cả campaigns</a>
        </div>
    </div>

    {{-- Stats --}}
    <div class="buyer-stats">
        <div class="buyer-stat"><div class="buyer-stat-n">{{ $campaign->bookingLines->count() }}</div><div class="buyer-stat-l">Màn hình</div></div>
        <div class="buyer-stat"><div class="buyer-stat-n">{{ number_format($campaign->total_estimated_cost, 0, ',', '.') }}</div><div class="buyer-stat-l">Chi phí ước tính (₫)</div></div>
        <div class="buyer-stat"><div class="buyer-stat-n">{{ number_format($campaign->total_actual_impressions) }}</div><div class="buyer-stat-l">Impressions thực tế</div></div>
        <div class="buyer-stat"><div class="buyer-stat-n">{{ $campaign->delivery_rate }}%</div><div class="buyer-stat-l">Delivery rate</div></div>
    </div>

    {{-- Booking lines --}}
    <div class="buyer-section">
        <h2 class="buyer-section-title">Danh sách màn hình</h2>
        <div class="buyer-campaign-list">
            @foreach($campaign->bookingLines as $line)
            <div class="buyer-campaign-row">
                <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
                    <img src="{{ $line->screen->spec?->photo ?? '' }}" style="width:40px;height:40px;border-radius:8px;object-fit:cover;background:var(--bg2);flex-shrink:0">
                    <div style="min-width:0">
                        <div style="font-size:13px;font-weight:700;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $line->screen->name }}</div>
                        <div style="font-size:11px;color:var(--t4)">{{ $line->screen->owner?->name }} &middot; {{ $line->start_date->format('d/m') }} → {{ $line->end_date->format('d/m') }}</div>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:13px;font-weight:700;color:var(--t1)">{{ number_format($line->estimated_cost, 0, ',', '.') }} ₫</div>
                    <span class="badge {{ $statusColors[$line->status] ?? 'b-gray' }}" style="font-size:10px">{{ $statusLabels[$line->status] ?? $line->status }}</span>

                    {{-- Hủy đặt chỗ.
                         Số tiền hoàn do MÁY CHỦ tính (xem BuyerCampaignController::cancelQuotes);
                         ở đây chỉ hiển thị lại. Gọi là "dự kiến" vì tiền đã trả được phân bổ
                         theo các dòng còn mở, nên hủy dòng khác trước sẽ làm con số này đổi —
                         con số quyết định là con số CancellationService tính trong transaction. --}}
                    @php($quote = ($cancelQuotes ?? [])[$line->id] ?? null)
                    @if($quote)
                    <form method="POST"
                          action="{{ route('buyer.campaigns.lines.cancel', [$campaign, $line]) }}"
                          style="margin-top:6px"
                          onsubmit="return confirm(@js(
                              $quote['refundable'] > 0
                                  ? 'Hủy đặt chỗ trên màn hình này? Còn ' . $quote['days_before'] . ' ngày tới ngày chạy, bạn được hoàn ' . $quote['refund_pct'] . '% — dự kiến ' . number_format($quote['refundable'], 0, ',', '.') . ' ₫.'
                                  : 'Hủy đặt chỗ trên màn hình này? Còn ' . $quote['days_before'] . ' ngày tới ngày chạy nên theo chính sách hủy, lần hủy này KHÔNG được hoàn tiền.'
                          ))">
                        @csrf
                        <button type="submit"
                                style="background:none;border:none;padding:0;font-size:11px;font-weight:600;color:var(--red);cursor:pointer;text-decoration:underline">
                            Hủy đặt chỗ
                        </button>
                        <div style="font-size:10px;color:var(--t4);margin-top:2px">
                            @if($quote['refundable'] > 0)
                                Hoàn dự kiến {{ number_format($quote['refundable'], 0, ',', '.') }} ₫ ({{ $quote['refund_pct'] }}%)
                            @else
                                Không được hoàn tiền
                            @endif
                        </div>
                    </form>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        {{-- Chính sách đọc từ config, không viết lại bằng chữ: viết lại là
             để nó lệch khỏi con số máy chủ đang áp dụng mà không ai biết. --}}
        @if(! empty($cancelQuotes))
        <div style="font-size:11px;color:var(--t4);margin-top:10px;line-height:1.6">
            Chính sách hủy, tính theo số ngày còn lại tới ngày chạy của từng màn hình:
            @foreach(config('pricing.refund_tiers', []) as $tier)
                {{ $tier['min_days_before'] > 0 ? 'từ ' . $tier['min_days_before'] . ' ngày trở lên' : 'dưới ' . (config('pricing.refund_tiers')[$loop->index - 1]['min_days_before'] ?? 0) . ' ngày' }}
                hoàn {{ $tier['refund_pct'] }}%{{ $loop->last ? '.' : ';' }}
            @endforeach
            Sàn không giữ tiền — media owner hoàn trực tiếp cho bạn.
        </div>
        @endif
    </div>

    {{-- Đánh giá media owner (yêu cầu review mục 6).
         Chỉ hiện khi campaign đã chạy — đánh giá dịch vụ chưa được cung cấp thì
         không dựa trên trải nghiệm nào. --}}
    @if($reviewableOwners->isNotEmpty() || $myReviews->isNotEmpty())
    <div class="buyer-section">
        <h2 class="buyer-section-title">Đánh giá media owner</h2>

        @error('rating')
            <div class="cart-alert cart-alert-err">{{ $message }}</div>
        @enderror

        @foreach($myReviews as $review)
            <div class="rv-done">
                <div class="rv-done-hd">
                    <span class="rv-done-owner">{{ $review->owner->name }}</span>
                    <span class="rv-stars" aria-label="{{ $review->rating }} trên 5 sao">
                        {{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}
                    </span>
                </div>
                @if($review->comment)
                    <p class="rv-done-cmt">{{ $review->comment }}</p>
                @endif
                <div class="rv-done-st">
                    @if($review->status === \App\Models\OwnerReview::STATUS_PUBLISHED)
                        Đã hiển thị công khai
                    @elseif($review->status === \App\Models\OwnerReview::STATUS_REJECTED)
                        Không được duyệt hiển thị
                    @else
                        Đang chờ kiểm duyệt
                    @endif
                </div>
            </div>
        @endforeach

        @foreach($reviewableOwners as $owner)
            <form method="POST" action="{{ route('buyer.campaigns.reviews.store', $campaign) }}" class="rv-form">
                @csrf
                <input type="hidden" name="owner_id" value="{{ $owner->id }}">
                <div class="rv-form-hd">Đánh giá <strong>{{ $owner->name }}</strong></div>

                <div class="rv-rate">
                    @for($i = 5; $i >= 1; $i--)
                        <input type="radio" name="rating" id="r-{{ $owner->id }}-{{ $i }}" value="{{ $i }}" required>
                        <label for="r-{{ $owner->id }}-{{ $i }}" title="{{ $i }} sao">★</label>
                    @endfor
                </div>

                <textarea name="comment" rows="3" maxlength="2000"
                          placeholder="Nhận xét về chất lượng dịch vụ, đúng hẹn, hỗ trợ... (không bắt buộc)"></textarea>

                <button type="submit" class="btn btn-p rv-submit">Gửi đánh giá</button>
            </form>
        @endforeach
    </div>
    @endif

    {{-- Activity log --}}
    <div class="buyer-section">
        <h2 class="buyer-section-title">Lịch sử hoạt động</h2>
        <div class="buyer-campaign-list">
            @foreach($campaign->activities as $a)
            <div class="buyer-campaign-row">
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--t1)">{{ $a->description }}</div>
                    <div style="font-size:11px;color:var(--t4)">{{ $a->created_at->format('d/m/Y H:i') }} &middot; {{ $a->user?->name ?? 'Hệ thống' }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
