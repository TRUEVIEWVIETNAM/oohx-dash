@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Thanh toán — {{ $campaign->name }} | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    <h1 class="wz-title">Thanh toán</h1>
    <p class="wz-sub">{{ $campaign->name }} &middot; {{ $campaign->code }}</p>

    @if($errors->any())
    <div class="auth-error" style="margin-bottom:16px">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="wz-layout">
        <div class="wz-main">
            {{-- Payment summary --}}
            <div class="wz-card">
                <div class="wz-card-title">Chi tiết thanh toán</div>
                <div class="pay-rows">
                    <div class="pay-row"><span>Tổng chi phí booking</span><span>{{ number_format($summary['total_cost'], 0, ',', '.') }} ₫</span></div>
                    <div class="pay-row"><span>VAT ({{ rtrim(rtrim(number_format(config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.') }}%)</span><span>{{ number_format($summary['vat'], 0, ',', '.') }} ₫</span></div>
                    <div class="pay-row pay-row-total"><span>Tổng cộng</span><span>{{ number_format($summary['total_cost_vat'], 0, ',', '.') }} ₫</span></div>
                    @if($summary['total_paid'] > 0)
                    <div class="pay-row" style="color:var(--grn)"><span>Đã thanh toán</span><span>-{{ number_format($summary['total_paid'], 0, ',', '.') }} ₫</span></div>
                    @endif
                    @if($summary['pending'] > 0)
                    <div class="pay-row" style="color:var(--org)"><span>Đang xử lý</span><span>-{{ number_format($summary['pending'], 0, ',', '.') }} ₫</span></div>
                    @endif
                    @if($summary['remaining'] > 0)
                    <div class="pay-row pay-row-total" style="color:var(--bl)"><span>Còn lại</span><span>{{ number_format($summary['remaining'], 0, ',', '.') }} ₫</span></div>
                    @endif
                </div>
            </div>

            @if($summary['is_fully_paid'])
            <div class="wz-card" style="margin-top:16px;text-align:center;padding:32px">
                <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:48px;height:48px;margin-bottom:12px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                <div style="font-size:18px;font-weight:700;color:var(--t1)">Đã thanh toán đủ</div>
                <div style="font-size:13px;color:var(--t3);margin-top:6px">Campaign sẽ tự động kích hoạt</div>
            </div>
            @else
            {{-- Thanh toán trực tiếp cho từng media owner.
                 OOHX không thu hộ — đúng như hồ sơ đăng ký với Bộ Công Thương:
                 "thanh toán trực tiếp giữa khách hàng và nhà cung cấp dịch vụ
                 quảng cáo; OOHX.NET hỗ trợ ghi nhận giao dịch và đối soát". --}}
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Thanh toán</div>
                <p class="pay-note">
                    Bạn chuyển khoản <strong>trực tiếp cho từng media owner</strong>.
                    OOHX không thu hộ, chỉ ghi nhận giao dịch và đối soát.
                    @if($byOwner->count() > 1)
                        Campaign này gồm màn hình của {{ $byOwner->count() }} media owner,
                        nên cần {{ $byOwner->count() }} lần chuyển khoản riêng.
                    @endif
                </p>

                @foreach($byOwner as $row)
                    @php
                        $owner = $row['owner'];
                    @endphp
                    <div class="pay-owner {{ $row['is_paid'] ? 'is-paid' : '' }}">
                        <div class="pay-owner-hd">
                            <div>
                                <div class="pay-owner-name">{{ $owner->legal_name ?: $owner->name }}</div>
                                @if($owner->tax_code)
                                    <div class="pay-owner-tax">MST: {{ $owner->tax_code }}</div>
                                @endif
                            </div>
                            @if($row['is_paid'])
                                <span class="badge b-green">Đã ghi nhận</span>
                            @endif
                        </div>

                        <div class="pay-bank-info">
                            @if($owner->hasBankDetails())
                                <div class="pay-bank-row"><span>Ngân hàng</span><span>{{ $owner->bank_name }}</span></div>
                                <div class="pay-bank-row"><span>Số tài khoản</span><span style="font-weight:700;letter-spacing:1px">{{ $owner->bank_account_number }}</span></div>
                                <div class="pay-bank-row"><span>Chủ tài khoản</span><span>{{ $owner->bank_account_name }}</span></div>
                                @if($owner->bank_branch)
                                    <div class="pay-bank-row"><span>Chi nhánh</span><span>{{ $owner->bank_branch }}</span></div>
                                @endif
                                <div class="pay-bank-row"><span>Nội dung CK</span><span style="font-weight:700;color:var(--bl)">{{ $campaign->code }}</span></div>
                            @else
                                {{-- Nói thẳng ra thay vì hiện một khối trống hoặc số giả. --}}
                                <div class="pay-bank-missing">
                                    Media owner này chưa cung cấp thông tin tài khoản nhận tiền.
                                    Vui lòng liên hệ OOHX qua hotline {{ config('policies.company.hotline') }}
                                    để được hỗ trợ.
                                </div>
                            @endif
                            <div class="pay-bank-row"><span>Chi phí</span><span>{{ number_format($row['cost'], 0, ',', '.') }} ₫</span></div>
                            <div class="pay-bank-row"><span>VAT ({{ rtrim(rtrim(number_format(config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.') }}%)</span><span>{{ number_format($row['vat'], 0, ',', '.') }} ₫</span></div>
                            <div class="pay-bank-row"><span>Cần chuyển</span><span style="font-weight:700">{{ number_format($row['remaining'], 0, ',', '.') }} ₫</span></div>
                        </div>

                        @if(! $row['is_paid'] && $owner->hasBankDetails())
                            <form method="POST" action="{{ route('buyer.payment.process', $campaign) }}">
                                @csrf
                                <input type="hidden" name="method" value="bank_transfer">
                                <input type="hidden" name="owner_id" value="{{ $owner->id }}">
                                <input type="hidden" name="amount" value="{{ $row['remaining'] }}">
                                {{-- Mã riêng cho LẦN xác nhận này. Bấm hai lần trên cùng
                                     biểu mẫu gửi lại cùng mã nên không tạo hai khoản; tải
                                     lại trang để trả tiếp phần còn lại thì có mã mới.
                                     Trước đây dùng token CSRF của phiên, mà token đó không
                                     đổi giữa các lần trả nên lần trả thứ hai nhận lại đúng
                                     khoản đã hoàn tất (Codex R07). --}}
                                <input type="hidden" name="payment_nonce" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                                <label class="consent">
                                    <input type="checkbox" name="accept_terms" value="1" required>
                                    <span>
                                        Bằng cách thanh toán, tôi đồng ý với
                                        <a href="{{ route('fp.policy', 'quy-che-hoat-dong') }}" target="_blank" rel="noopener">Quy chế hoạt động</a>
                                    </span>
                                </label>

                                <button type="submit" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;height:44px;font-size:14px">
                                    Xác nhận đã chuyển khoản cho {{ $owner->name }}
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
            @endif

            {{-- Payment history --}}
            @if($payments->isNotEmpty())
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Lịch sử thanh toán</div>
                <div class="pay-history">
                    @foreach($payments as $p)
                    <div class="pay-history-row">
                        <div>
                            <div style="font-weight:600;color:var(--t1);font-size:13px">{{ $p->invoice_number ?? $p->transaction_ref }}</div>
                            <div style="font-size:11px;color:var(--t4)">{{ ucfirst(str_replace('_', ' ', $p->method)) }} &middot; {{ $p->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-weight:700;color:var(--t1)">{{ number_format($p->amount, 0, ',', '.') }} ₫</div>
                            @php
                                $pc = ['pending'=>'b-org','processing'=>'b-org','completed'=>'b-grn','failed'=>'b-red','refunded'=>'b-gray'];
                                $pl = ['pending'=>'Chờ xác nhận','processing'=>'Đang xử lý','completed'=>'Thành công','failed'=>'Thất bại','refunded'=>'Hoàn tiền'];
                            @endphp
                            <span class="badge {{ $pc[$p->status] ?? 'b-gray' }}" style="font-size:10px">{{ $pl[$p->status] ?? $p->status }}</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <div class="wz-sidebar">
            <div class="wz-actions-card">
                <div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:8px">{{ $campaign->name }}</div>
                <div style="font-size:12px;color:var(--t4);margin-bottom:16px">{{ $campaign->bookingLines()->count() }} màn hình &middot; {{ $campaign->start_date->format('d/m/Y') }} → {{ $campaign->end_date->format('d/m/Y') }}</div>
                <a href="{{ route('buyer.campaigns.show', $campaign) }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">Xem Campaign</a>
            </div>
            <div style="margin-top:12px;padding:14px;background:var(--bg2);border-radius:12px;font-size:12px;color:var(--t3);line-height:1.6">
                <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:14px;height:14px;vertical-align:middle"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                Thanh toán bảo mật bởi OOHX. Campaign sẽ tự động kích hoạt sau khi xác nhận thanh toán.
            </div>
        </div>
    </div>
</div>
@endsection
