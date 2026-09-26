@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Plan của tôi | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    <div class="cart-header">
        <div>
            <h1 class="cart-title">Plan của tôi</h1>
            <p class="cart-sub">{{ $items->count() }} màn hình trong plan</p>
        </div>
        @if($items->count())
        <a href="{{ route('fp.listing') }}" class="btn btn-s btn-sm">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;flex-shrink:0"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Thêm màn hình
        </a>
        @endif
    </div>

    @if(session('success'))
    <div class="cart-alert">{{ session('success') }}</div>
    @endif

    @if($items->isEmpty())
    <div class="cart-empty">
        <svg viewBox="0 0 24 24" fill="var(--t4)" style="width:56px;height:56px;margin-bottom:14px"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96C5 16.1 6.9 18 9 18h12v-2H9.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63H19c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 23.43 5H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
        <div style="font-size:16px;font-weight:700;color:var(--t1);margin-bottom:6px">Plan trống</div>
        <div style="font-size:14px;color:var(--t3);margin-bottom:20px">Thêm màn hình vào plan từ trang khám phá</div>
        <a href="{{ route('fp.listing') }}" class="btn btn-p btn-sm">Khám phá Inventory</a>
    </div>
    @else
    <div class="cart-layout">
        <div class="cart-items">
            @foreach($items as $item)
            @php
                $screen = $item->screen;
                $photo = $screen->spec?->photo ?? 'https://placehold.co/200x200/F5F5F7/6E6E73?text=No+Photo';
                $province = $screen->site?->province?->name ?? $screen->site?->city ?? '';
                $commune = $screen->site?->commune?->name ?? '';
                $loc = collect([$province, $commune])->filter()->join(' > ');
            @endphp
            <div class="cart-item">
                <div class="cart-item-img">
                    <img src="{{ $photo }}" alt="{{ $screen->name }}" loading="lazy">
                </div>
                <div class="cart-item-body">
                    <div class="cart-item-top">
                        <div>
                            <a href="{{ route('fp.detail', $screen->slug ?? $screen->id) }}" class="cart-item-name">{{ $screen->name }}</a>
                            <div class="cart-item-loc">{{ $loc }} &middot; {{ $screen->owner?->name ?? '' }}</div>
                        </div>
                        <form method="POST" action="{{ route('buyer.cart.remove', $item) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="cart-item-remove" title="Xóa">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                            </button>
                        </form>
                    </div>
                    <div class="cart-item-meta">
                        <div class="cart-item-field">
                            <span class="cart-item-label">Thời gian</span>
                            <span>{{ $item->start_date->format('d/m/Y') }} → {{ $item->end_date->format('d/m/Y') }}</span>
                        </div>
                        @if($item->pricing_model === 'cpm')
                        <div class="cart-item-field">
                            <span class="cart-item-label">Kiểu bán</span>
                            <span style="color:var(--bl);font-weight:600">CPM</span>
                        </div>
                        <div class="cart-item-field">
                            <span class="cart-item-label">Chi tiết</span>
                            <span>{{ number_format($item->unit_price, 0, ',', '.') }} ₫/CPM × {{ number_format($item->booked_cpms) }} CPM</span>
                        </div>
                        @else
                        <div class="cart-item-field">
                            <span class="cart-item-label">Kiểu bán</span>
                            <span style="color:var(--grn);font-weight:600">I/O Booking</span>
                        </div>
                        <div class="cart-item-field">
                            <span class="cart-item-label">Chi tiết</span>
                            <span>{{ number_format($item->unit_price, 0, ',', '.') }} ₫ × {{ $item->screen_count }} mh × {{ $item->duration_units }} {{ $item->duration_unit === 'week' ? 'tuần' : 'tháng' }}</span>
                        </div>
                        @endif
                        <div class="cart-item-field">
                            <span class="cart-item-label">Ước tính</span>
                            <span style="font-weight:700;color:var(--t1)">{{ number_format($item->estimated_cost, 0, ',', '.') }} ₫</span>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="cart-sidebar">
            <div class="cart-summary">
                <div class="cart-summary-title">Tóm tắt Plan</div>
                <div class="cart-summary-row">
                    <span>Số màn hình</span>
                    <span style="font-weight:700">{{ $items->count() }}</span>
                </div>
                <div class="cart-summary-row">
                    <span>Tổng ước tính</span>
                    <span style="font-weight:700;color:var(--bl)">{{ number_format($items->sum('estimated_cost'), 0, ',', '.') }} ₫</span>
                </div>
                <div class="cart-summary-row">
                    <span>VAT ({{ rtrim(rtrim(number_format(config('pricing.vat_rate') * 100, 2, '.', ''), '0'), '.') }}%)</span>
                    <span>{{ number_format($items->sum('estimated_cost') * config('pricing.vat_rate'), 0, ',', '.') }} ₫</span>
                </div>
                <div class="cart-summary-total">
                    <span>Tổng cộng</span>
                    <span>{{ number_format($items->sum('estimated_cost') * (1 + config('pricing.vat_rate')), 0, ',', '.') }} ₫</span>
                </div>
                <a href="{{ route('buyer.booking.create') }}" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px;margin-top:14px">
                    Tạo Campaign
                </a>
                <div style="text-align:center;font-size:11px;color:var(--t4);margin-top:8px">Bạn sẽ review lại trước khi gửi booking</div>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
