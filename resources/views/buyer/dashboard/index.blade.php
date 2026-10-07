@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Dashboard | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    {{-- Welcome header --}}
    <div class="buyer-welcome">
        <div>
            <h1 class="buyer-welcome-title">Xin chào, {{ auth()->user()->name }}</h1>
            <p class="buyer-welcome-sub">{{ $org->name }} &middot; {{ ucfirst($org->type) }}</p>
        </div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">
            <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px;flex-shrink:0"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo Campaign
        </a>
    </div>

    {{-- Stats cards --}}
    <div class="buyer-stats">
        <div class="buyer-stat">
            <div class="buyer-stat-n">{{ $campaignCounts['draft'] }}</div>
            <div class="buyer-stat-l">Nháp</div>
        </div>
        <div class="buyer-stat">
            <div class="buyer-stat-n" style="color:var(--org)">{{ $campaignCounts['pending'] }}</div>
            <div class="buyer-stat-l">Chờ duyệt</div>
        </div>
        <div class="buyer-stat">
            <div class="buyer-stat-n" style="color:var(--grn)">{{ $campaignCounts['active'] }}</div>
            <div class="buyer-stat-l">Đang chạy</div>
        </div>
        <div class="buyer-stat">
            <div class="buyer-stat-n">{{ $campaignCounts['total'] }}</div>
            <div class="buyer-stat-l">Tổng cộng</div>
        </div>
    </div>

    {{-- Recent campaigns --}}
    <div class="buyer-section">
        <div class="buyer-section-head">
            <h2 class="buyer-section-title">Campaign gần đây</h2>
        </div>
        @if($recentCampaigns->isEmpty())
        <div class="buyer-empty">
            <svg viewBox="0 0 24 24" fill="var(--t4)" style="width:48px;height:48px;margin-bottom:12px"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            <div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:4px">Chưa có campaign nào</div>
            <div style="font-size:13px;color:var(--t4);margin-bottom:16px">Bắt đầu bằng cách khám phá inventory và tạo campaign đầu tiên</div>
            <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">Khám phá Inventory</a>
        </div>
        @else
        <div class="buyer-campaign-list">
            @foreach($recentCampaigns as $c)
            <div class="buyer-campaign-row">
                <div>
                    <div style="font-size:14px;font-weight:700;color:var(--t1)">{{ $c->name }}</div>
                    <div style="font-size:12px;color:var(--t4);margin-top:2px">{{ $c->code }} &middot; {{ $c->start_date->format('d/m/Y') }} → {{ $c->end_date->format('d/m/Y') }}</div>
                </div>
                <span class="badge {{ $c->status === 'active' ? 'b-grn' : ($c->status === 'pending_approval' ? 'b-org' : 'b-gray') }}">{{ $c->status }}</span>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Quick links --}}
    <div class="buyer-quick">
        <a href="{{ url('/explore') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--bl)" style="width:24px;height:24px"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <div class="buyer-quick-title">Khám phá Inventory</div>
            <div class="buyer-quick-sub">Tìm kiếm biển quảng cáo</div>
        </a>
        <a href="{{ url('/map') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--grn)" style="width:24px;height:24px"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
            <div class="buyer-quick-title">Bản đồ</div>
            <div class="buyer-quick-sub">Xem vị trí trên bản đồ</div>
        </a>
        <a href="{{ url('/owners') }}" class="buyer-quick-card">
            <svg viewBox="0 0 24 24" fill="var(--org)" style="width:24px;height:24px"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            <div class="buyer-quick-title">Media Owners</div>
            <div class="buyer-quick-sub">Xem đối tác verified</div>
        </a>
    </div>
</div>
@endsection
