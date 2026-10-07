@extends('frontpage.layouts.app', ['activeNav' => 'dashboard', 'bodyClass' => ''])

@section('title', 'Chiến dịch | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">
    <div class="buyer-welcome">
        <div><h1 class="buyer-welcome-title">Chiến dịch</h1></div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">
            <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Tạo mới
        </a>
    </div>

    @if($campaigns->isEmpty())
    <div class="buyer-empty">
        <div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:16px">Chưa có campaign nào</div>
        <a href="{{ url('/explore') }}" class="btn btn-p btn-sm">Khám phá Inventory</a>
    </div>
    @else
    <div class="buyer-campaign-list">
        @php
            $statusColors = ['draft'=>'b-gray','pending_approval'=>'b-org','approved'=>'b-bl','rejected'=>'b-red','active'=>'b-grn','paused'=>'b-org','completed'=>'b-gray','cancelled'=>'b-red'];
            $statusLabels = ['draft'=>'Nháp','pending_approval'=>'Chờ duyệt','approved'=>'Đã duyệt','rejected'=>'Từ chối','active'=>'Đang chạy','paused'=>'Tạm dừng','completed'=>'Hoàn thành','cancelled'=>'Đã hủy'];
        @endphp
        @foreach($campaigns as $c)
        <a href="{{ route('buyer.campaigns.show', $c) }}" class="buyer-campaign-row" style="text-decoration:none;color:inherit">
            <div style="flex:1;min-width:0">
                <div style="font-size:14px;font-weight:700;color:var(--t1)">{{ $c->name }}</div>
                <div style="font-size:12px;color:var(--t4);margin-top:2px">{{ $c->code }} &middot; {{ $c->start_date->format('d/m/Y') }} → {{ $c->end_date->format('d/m/Y') }} &middot; {{ $c->total_screens }} screens</div>
            </div>
            <span class="badge {{ $statusColors[$c->status] ?? 'b-gray' }}">{{ $statusLabels[$c->status] ?? $c->status }}</span>
        </a>
        @endforeach
    </div>
    <div style="margin-top:20px">{{ $campaigns->links() }}</div>
    @endif
</div>
@endsection
