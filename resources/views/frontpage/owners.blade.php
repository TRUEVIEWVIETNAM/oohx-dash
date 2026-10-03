@extends('frontpage.layouts.app', ['activeNav' => 'owners'])

@section('title', 'Media Owners | OOHX')

@section('content')
<div class="pg-hero"><div class="w"><div style="font-size:13px;font-weight:700;color:var(--bl);letter-spacing:.3px;margin-bottom:8px">ĐỐI TÁC</div><h1>Media Owners</h1><p>{{ $owners->total() }} đối tác đang hoạt động. Inventory thật, data thật. Đặt booking trực tiếp không qua trung gian.</p><form class="pg-hero-search" method="GET" action="{{ route('fp.owners') }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t4)" style="width:18px;height:18px;flex-shrink:0"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg><input name="q" value="{{ request('q') }}" placeholder="Tìm media owner theo tên, khu vực, format…">@if(request('type'))<input type="hidden" name="type" value="{{ request('type') }}">@endif</form></div></div><div class="w"><div class="cat-chips"><a class="cat-chip @if(! request('type')) on @endif" href="{{ route('fp.owners', array_filter(['q' => request('q')])) }}">Tất cả</a>@foreach($venueTypes->take(6) as $vt)
<a class="cat-chip @if(request('type') === $vt['type']) on @endif" href="{{ route('fp.owners', array_filter(['type' => $vt['type'], 'q' => request('q')])) }}">{{ $vt['label'] }} ({{ $vt['count'] }})</a>
@endforeach</div><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px"><div style="font-size:14px;font-weight:600;color:var(--t2)">Hiển thị <strong style="color:var(--bl)">{{ $owners->total() }}</strong> media owners</div>{{-- ĐÃ GỠ: ô sắp xếp (Nhiều inventory nhất).
     Nó không có `name`, không nằm trong form, và không JS nào bắt nó — bấm
     chọn thì thứ tự không đổi. Lựa chọn "Fill rate cao nhất" còn không tính được: chưa có dữ liệu phát sóng thật.
     `FrontpageService` cũng KHÔNG nhận tham số sắp xếp nào cho danh sách này,
     nên làm nó chạy là thêm tính năng mới vào Blade — CLAUDE.md mục 3 cấm
     điều đó trong giai đoạn chuyển đổi. Gỡ là cách sửa đúng phạm vi (F-15).

     Khi danh sách này chuyển sang Next.js (giai đoạn 6): thêm tham số sắp xếp
     vào API v2 trước, rồi mới dựng ô chọn đọc từ đó. --}}</div><div class="oc-grid oc-grid--full">
    @foreach($owners as $owner)
        @include('frontpage.partials.owner-card', ['owner' => $owner, 'variant' => 'full'])
    @endforeach
</div><div style="display:flex;justify-content:center;padding-bottom:64px">{{ $owners->links() }}</div></div>
@endsection

{{-- ĐÃ GỠ: khối JS gắn click cho .cat-chip.
     Nó chỉ bỏ class `on` ở mọi chip rồi thêm vào chip vừa bấm — tức chip
     sáng lên như đã lọc, trong khi danh sách bên dưới không đổi một dòng.
     Tệ hơn một nút chết im lặng, vì nó phản hồi như đã làm việc (F-15).

     Nay chip là link `?type=`, và chip nào đang chọn do máy chủ quyết từ
     `request('type')` — nên trạng thái hiển thị không thể lệch khỏi dữ
     liệu đang hiện. --}}

