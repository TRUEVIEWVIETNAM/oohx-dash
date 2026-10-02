@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Xác nhận Booking — {{ $campaign->name }} | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    <div class="wz-steps">
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Thông tin</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Nội dung quảng cáo</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step on"><div class="wz-step-n">3</div><div class="wz-step-l">Xác nhận</div></div>
    </div>

    <h1 class="wz-title">Xác nhận & Gửi Booking</h1>
    <p class="wz-sub">{{ $campaign->name }} &middot; {{ $campaign->code }}</p>

    @if($errors->any())
    <div class="auth-error" style="margin-bottom:16px">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @if(!empty($conflicts))
    <div class="wz-conflict">
        <div style="font-weight:700;margin-bottom:6px">Cảnh báo: Xung đột SOV</div>
        @foreach($conflicts as $c)
        <div>{{ $c['screen_name'] }} ({{ $c['dates'] }}): yêu cầu {{ $c['requested'] }}%, còn trống {{ $c['available'] }}%</div>
        @endforeach
    </div>
    @endif

    <div class="wz-layout">
        <div class="wz-main">
            {{-- Campaign info --}}
            <div class="wz-card">
                <div class="wz-card-title">Thông tin Campaign</div>
                <div class="wz-info-grid">
                    <div class="wz-info-item"><div class="wz-info-l">Tên</div><div class="wz-info-v">{{ $campaign->name }}</div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Nhãn hàng</div><div class="wz-info-v">{{ $campaign->brand_name ?? '—' }}</div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Ngành hàng</div><div class="wz-info-v">{{ $campaign->category ?? '—' }}</div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Thời gian</div><div class="wz-info-v">{{ $campaign->start_date->format('d/m/Y') }} → {{ $campaign->end_date->format('d/m/Y') }}</div></div>
                    <div class="wz-info-item"><div class="wz-info-l">Ngân sách</div><div class="wz-info-v">{{ $campaign->total_budget ? number_format($campaign->total_budget, 0, ',', '.') . ' ₫' : '—' }}</div></div>
                </div>
            </div>

            {{-- Booking lines --}}
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Danh sách màn hình ({{ $lines->count() }})</div>
                <div class="wz-lines">
                    @foreach($lines as $line)
                    <div class="wz-line">
                        <div class="wz-line-img">
                            <img src="{{ $line->screen->spec?->photo ?? '' }}" alt="">
                        </div>
                        <div class="wz-line-body">
                            <div style="font-weight:700;color:var(--t1);font-size:13px">{{ $line->screen->name }}</div>
                            <div style="font-size:11px;color:var(--t4)">{{ $line->screen->owner?->name }} &middot; {{ $line->screen->site?->city }}</div>
                            <div style="font-size:12px;color:var(--t3);margin-top:4px">
                                {{ $line->start_date->format('d/m') }} → {{ $line->end_date->format('d/m') }} &middot;
                                SOV {{ $line->share_of_voice_pct }}% &middot;
                                {{ $line->spot_length }}s
                            </div>
                        </div>
                        <div style="text-align:right;white-space:nowrap">
                            <div style="font-size:14px;font-weight:800;color:var(--t1)">{{ number_format($line->estimated_cost, 0, ',', '.') }} ₫</div>
                            <div style="font-size:11px;color:var(--t4)">CPM {{ number_format($line->floor_cpm_at_booking, 0, ',', '.') }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Creatives --}}
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Creatives ({{ $creatives->count() }})</div>
                @if($creatives->isEmpty())
                <div style="font-size:13px;color:var(--t4);padding:12px 0">Chưa upload creative. Bạn có thể upload sau khi campaign được duyệt.</div>
                @else
                <div class="wz-creative-list">
                    @foreach($creatives as $c)
                    <div class="wz-creative-item">
                        <div class="wz-creative-thumb">
                            @if($c->type === 'image')<img src="{{ $c->file_url }}" alt="">@else
                            <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--bg2)"><svg viewBox="0 0 24 24" fill="var(--t4)" style="width:20px;height:20px"><path d="M8 5v14l11-7z"/></svg></div>
                            @endif
                        </div>
                        <div class="wz-creative-info">
                            <div style="font-weight:600;color:var(--t1)">{{ $c->name }}</div>
                            <div style="font-size:11px;color:var(--t4)">{{ strtoupper($c->type) }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        {{-- Sidebar: total + submit --}}
        <div class="wz-sidebar">
            <div class="cart-summary">
                <div class="cart-summary-title">Tổng kết Booking</div>
                <div class="cart-summary-row"><span>Số màn hình</span><span style="font-weight:700">{{ $lines->count() }}</span></div>
                <div class="cart-summary-row"><span>Tổng ước tính</span><span style="font-weight:700">{{ number_format($lines->sum('estimated_cost'), 0, ',', '.') }} ₫</span></div>
                <div class="cart-summary-row"><span>VAT ({{ rtrim(rtrim(number_format(config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.') }}%)</span><span>{{ number_format($lines->sum('estimated_cost') * config('pricing.vat_rate'), 0, ',', '.') }} ₫</span></div>
                <div class="cart-summary-total">
                    <span>Tổng cộng</span>
                    <span>{{ number_format($lines->sum('estimated_cost') * (1 + config('pricing.vat_rate')), 0, ',', '.') }} ₫</span>
                </div>

                @if(empty($conflicts))
                <form method="POST" action="{{ route('buyer.booking.submit', $campaign) }}" style="margin-top:16px">
                    @csrf
                    {{-- name="confirm_accuracy": trước đây checkbox này chỉ có id nên
                         không hề được gửi lên server — tắt JS là qua. --}}
                    <label class="consent">
                        <input type="checkbox" name="confirm_accuracy" value="1" required>
                        <span>Tôi xác nhận thông tin booking chính xác. Booking sẽ được gửi đến media owner để phê duyệt trong vòng 48h.</span>
                    </label>

                    <label class="consent">
                        <input type="checkbox" name="accept_terms" value="1" required>
                        <span>
                            Tôi đã đọc và đồng ý với
                            <a href="{{ route('fp.policy', 'quy-che-hoat-dong') }}" target="_blank" rel="noopener">Quy chế hoạt động</a>
                            và
                            <a href="{{ route('fp.policy', 'chinh-sach-bao-mat') }}" target="_blank" rel="noopener">Chính sách bảo mật</a>
                        </span>
                    </label>
                    <button type="submit" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;height:48px;font-size:15px">
                        <svg viewBox="0 0 24 24" fill="#fff" style="width:18px;height:18px"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        Gửi Booking
                    </button>
                </form>
                @else
                <div style="margin-top:16px;padding:12px;background:rgba(255,59,48,.06);border-radius:10px;font-size:12px;color:var(--red);text-align:center">
                    Không thể gửi booking — có xung đột SOV
                </div>
                @endif

                <div style="text-align:center;font-size:11px;color:var(--t4);margin-top:10px">
                    <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:12px;height:12px;vertical-align:middle"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    Thanh toán bảo mật bởi OOHX
                </div>
            </div>

            <div class="wz-actions-card" style="margin-top:12px">
                <a href="{{ route('buyer.booking.creative', $campaign) }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">
                    <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                    Quay lại
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
