@extends('frontpage.layouts.app', ['activeNav' => 'agency'])

@section('title', 'Agencies & Brands | OOHX')

@section('content')
<div class="pg-hero">
    <div class="w">
        <div style="font-size:13px;font-weight:700;color:var(--bl);letter-spacing:.3px;margin-bottom:8px">TRUSTED PARTNERS</div>
        <h1>Agencies & Brands</h1>
        <p>{{ $agencies->total() }}+ agency và brand đang sử dụng OOHX để book inventory. Tham gia cộng đồng planning OOH chuyên nghiệp.</p>
        <div class="pg-hero-search">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t4)" style="width:18px;height:18px;flex-shrink:0"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input name="q" value="{{ request('q') }}" placeholder="Tìm agency/brand theo tên…">
        </div>
    </div>
</div>

<div class="w">
    {{-- ĐÃ GỠ: cụm chip "Tất cả / Đại lý / Nhãn hàng / Khách hàng".
     Khối JS kèm theo chỉ bỏ class `on` ở mọi chip rồi thêm vào chip vừa
     bấm — chip sáng lên như đã lọc, danh sách bên dưới không đổi một dòng.
     Phản hồi như đã làm việc trong khi không làm gì (F-15).

     Khác trang /owners, ở đây KHÔNG nối được: `getAgencies()` cố định
     `type = 'agency'` và không nhận tham số lọc nào, nên ba nhãn kia
     không có chỗ nào ở backend để trỏ tới. Làm chúng chạy là thêm tính
     năng mới — CLAUDE.md mục 3 cấm trong giai đoạn chuyển đổi. --}}
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
        <div style="font-size:14px;font-weight:600;color:var(--t2)">Hiển thị <strong style="color:var(--bl)">{{ $agencies->total() }}</strong> agencies</div>
        {{-- ĐÃ GỠ: ô sắp xếp (Nhiều campaigns nhất).
     Nó không có `name`, không nằm trong form, và không JS nào bắt nó — bấm
     chọn thì thứ tự không đổi. 
     `FrontpageService` cũng KHÔNG nhận tham số sắp xếp nào cho danh sách này,
     nên làm nó chạy là thêm tính năng mới vào Blade — CLAUDE.md mục 3 cấm
     điều đó trong giai đoạn chuyển đổi. Gỡ là cách sửa đúng phạm vi (F-15).

     Khi danh sách này chuyển sang Next.js (giai đoạn 6): thêm tham số sắp xếp
     vào API v2 trước, rồi mới dựng ô chọn đọc từ đó. --}}
    </div>

    @if($agencies->isEmpty())
    <div style="text-align:center;padding:64px 20px;color:var(--t4)">
        <svg viewBox="0 0 24 24" fill="var(--ln2)" style="width:64px;height:64px;margin:0 auto 16px;display:block"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        <div style="font-size:16px;font-weight:700;color:var(--t3);margin-bottom:6px">Chưa có agency nào</div>
        <div style="font-size:13px">Hãy là người đầu tiên tham gia cộng đồng OOHX</div>
    </div>
    @else
    <div class="oc-grid oc-grid--full">
        @foreach($agencies as $agency)
            @include('frontpage.partials.agency-card', ['agency' => $agency])
        @endforeach
    </div>
    <div style="display:flex;justify-content:center;padding-bottom:64px">{{ $agencies->links() }}</div>
    @endif
</div>
@endsection


