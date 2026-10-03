@extends('frontpage.layouts.app', ['activeNav' => 'home'])

@section('title', 'OOHX – Marketplace OOH/DOOH')

@push('head')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
@include('frontpage.partials.map-styles')
<style>@media(min-width:768px){#login-btn,#signup-btn{display:inline-flex!important}}</style>
@endpush

@section('content')

<section class="hero">
  <div class="w" style="text-align:center">
    <div style="animation:fade-up .8s var(--ease) both">
      <div class="hero-badge" style="display:inline-flex">
        <div class="hero-badge-dot"></div>
        {{ number_format($stats['total_screens']) }} inventory đang mở đặt lịch
      </div>
    </div>

    <div class="hero-text">
      <h1 class="hero-hed" style="margin-bottom:18px">
        Marketplace OOH/DOOH<br>
        <span class="acc">thông minh nhất Việt Nam.</span>
      </h1>
    </div>

    {{-- Chỉ nói những con số đọc được từ CSDL.
         Bản trước hứa "dữ liệu traffic xác thực, AI hỗ trợ lên plan, tiếp cận
         30M+ khách hàng": không có nguồn nào cho 30M, không có tính năng AI lên
         plan, và số liệu traffic là do media owner tự công bố chứ chưa xác
         thực. Với một sàn đang nộp hồ sơ TMĐT thì đó là rủi ro pháp lý, không
         phải chuyện câu chữ (audit F-15). --}}
    <p class="hero-desc">
      Tìm, so sánh và đặt booking billboard · LED · LCD trong vài phút.
      {{ number_format($stats['total_screens']) }} vị trí từ {{ number_format($stats['total_owners']) }} media owner
      tại {{ number_format($stats['total_cities']) }} tỉnh thành.
    </p>

    <!-- Mega Search -->
    @php
      $searchChips = collect();
      $chipCityNames = ($topCities ?? collect())->take(2)->pluck('name');
      foreach (($venueTypes ?? collect())->take(3) as $i => $vt) {
          $chipCity = $chipCityNames[$i] ?? $chipCityNames[0] ?? '';
          $searchChips->push($vt['label'] . ($chipCity ? ' ' . $chipCity : ''));
      }
      if (($venueTypes ?? collect())->count() > 3) {
          $searchChips->push($venueTypes[3]['label']);
      }
    @endphp
    @include('frontpage.partials.mega-search', [
        'variant'          => 'hero',
        'locationsByRegion' => $locationsByRegion,
        'filters'          => $filters ?? ['networks' => collect(), 'formats' => $venueTypes ?? collect()],
        'searchChips'      => $searchChips,
    ])

    <!-- CTA buttons -->
    <div class="hero-actions" style="margin-top:28px">
      <a href="{{ route('fp.listing') }}" class="btn btn-p btn-lg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" style="width:18px;height:18px;flex-shrink:0"><path d="M3 3v8h8V3H3zm6 6H5V5h4v4zm-6 4v8h8v-8H3zm6 6H5v-4h4v4zm4-16v8h8V3h-8zm6 6h-4V5h4v4zm-6 4v8h8v-8h-8zm6 6h-4v-4h4v4z"/></svg> Khám phá Inventory</a>
      <a href="{{ route('fp.map') }}" class="btn btn-s btn-lg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t2)" style="width:18px;height:18px;flex-shrink:0"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg> Xem bản đồ</a>
    </div>
    {{-- "Miễn phí đăng ký" và "không phí môi giới" là chính sách thật, giữ lại.
         "30M+ impressions" không có nguồn — gỡ. --}}
    <div class="hero-hint">Miễn phí đăng ký · Không phí môi giới</div>

    <!-- Stats -->
    <div class="hero-stats">
      <div class="hstat"><div class="hstat-n">{{ number_format($stats['total_screens'] / 1000, 1) }}<span class="unit">K+</span></div><div class="hstat-l">Inventory</div></div>
      <div class="hstat"><div class="hstat-n">{{ $stats['total_cities'] }}</div><div class="hstat-l">Tỉnh thành</div></div>
      <div class="hstat"><div class="hstat-n">{{ $stats['total_owners'] }}<span class="unit">+</span></div><div class="hstat-l">Media owners</div></div>
    </div>
  </div>

  <!-- Browser mockup -->
  <div class="w" style="padding-bottom:0">
    <div class="hero-visual" style="position:relative;padding:0 20px">
      {{-- ĐÃ GỠ: thẻ "Live Impressions" với bộ đếm chạy bằng Math.random().
           Nó tự tăng vài chục lượt mỗi 1,4 giây và gắn tên một màn hình cụ thể
           ("LED Láng Hạ — hôm nay"), tức là bịa ra số liệu phát sóng của một
           vị trí có thật. Đường ghi bằng chứng phát sóng chỉ mới hoạt động từ
           giai đoạn 2 và hiện chưa có dữ liệu nào, nên không có gì để hiện ở
           đây (audit F-15).

           Khi có dữ liệu thật: đọc từ `impression_daily_rollups`, và nhớ rằng
           lượt phát có `played_at_clamped` đã bị loại khỏi bảng đó. --}}

      {{-- ĐÃ GỠ: thẻ "AI Match 94% — 12 vị trí đề xuất".
           Không có tính năng nào trong hệ thống tính ra con số này; cả "94%"
           lẫn "12 vị trí" đều viết cứng trong template. Quảng cáo một năng lực
           không tồn tại là chỗ nặng nhất của F-15. --}}

      <div class="hero-browser">
        <div class="hb-bar">
          <div class="hb-dots">
            <div class="hb-dot" style="background:#FF5F57"></div>
            <div class="hb-dot" style="background:#FEBC2E"></div>
            <div class="hb-dot" style="background:#28C840"></div>
          </div>
          <div class="hb-url">oohx.net</div>
        </div>
        <div class="hb-screen">
          <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA4MDAgNTAwIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCI+CiAgPGRlZnM+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9ImRhc2gtYmciIHgxPSIwIiB5MT0iMCIgeDI9IjEiIHkyPSIxIj4KICAgICAgPHN0b3Agb2Zmc2V0PSIwJSIgc3RvcC1jb2xvcj0iI0Y1RjVGNyIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjEwMCUiIHN0b3AtY29sb3I9IiNGRkZGRkYiLz4KICAgIDwvbGluZWFyR3JhZGllbnQ+CiAgPC9kZWZzPgogIDxyZWN0IHdpZHRoPSI4MDAiIGhlaWdodD0iNTAwIiBmaWxsPSJ1cmwoI2Rhc2gtYmcpIi8+CiAgPCEtLSBTaWRlYmFyIC0tPgogIDxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSIxODAiIGhlaWdodD0iNTAwIiBmaWxsPSIjMUQxRDFGIi8+CiAgPCEtLSBTaWRlYmFyIGxvZ28gYXJlYSAtLT4KICA8cmVjdCB4PSIxNiIgeT0iMTYiIHdpZHRoPSIxNDgiIGhlaWdodD0iMzYiIHJ4PSI4IiBmaWxsPSIjMkMyQzJFIi8+CiAgPHJlY3QgeD0iMjgiIHk9IjI1IiB3aWR0aD0iNjAiIGhlaWdodD0iMTYiIHJ4PSI0IiBmaWxsPSIjMDA3MUUzIi8+CiAgPHJlY3QgeD0iOTYiIHk9IjI4IiB3aWR0aD0iNTAiIGhlaWdodD0iMTAiIHJ4PSIzIiBmaWxsPSJyZ2JhKDI1NSwyNTUsMjU1LDAuNSkiLz4KICA8IS0tIFNpZGViYXIgbmF2IGl0ZW1zIC0tPgogIDxnPgogICAgPHJlY3QgeD0iMTAiIHk9IjY4IiB3aWR0aD0iMTYwIiBoZWlnaHQ9IjM2IiByeD0iOCIgZmlsbD0iIzAwNzFFMyIvPgogICAgPHJlY3QgeD0iMjgiIHk9Ijc5IiB3aWR0aD0iODAiIGhlaWdodD0iMTIiIHJ4PSIzIiBmaWxsPSJyZ2JhKDI1NSwyNTUsMjU1LDAuOSkiLz4KICAgIDxyZWN0IHg9IjEwIiB5PSIxMTIiIHdpZHRoPSIxNjAiIGhlaWdodD0iMzYiIHJ4PSI4IiBmaWxsPSJ0cmFuc3BhcmVudCIvPgogICAgPHJlY3QgeD0iMjgiIHk9IjEyMyIgd2lkdGg9IjcwIiBoZWlnaHQ9IjEyIiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjQpIi8+CiAgICA8cmVjdCB4PSIxMCIgeT0iMTU2IiB3aWR0aD0iMTYwIiBoZWlnaHQ9IjM2IiByeD0iOCIgZmlsbD0idHJhbnNwYXJlbnQiLz4KICAgIDxyZWN0IHg9IjI4IiB5PSIxNjciIHdpZHRoPSI4MCIgaGVpZ2h0PSIxMiIgcng9IjMiIGZpbGw9InJnYmEoMjU1LDI1NSwyNTUsMC40KSIvPgogICAgPHJlY3QgeD0iMTAiIHk9IjIwMCIgd2lkdGg9IjE2MCIgaGVpZ2h0PSIzNiIgcng9IjgiIGZpbGw9InRyYW5zcGFyZW50Ii8+CiAgICA8cmVjdCB4PSIyOCIgeT0iMjExIiB3aWR0aD0iNjUiIGhlaWdodD0iMTIiIHJ4PSIzIiBmaWxsPSJyZ2JhKDI1NSwyNTUsMjU1LDAuNCkiLz4KICAgIDxyZWN0IHg9IjEwIiB5PSIyNDQiIHdpZHRoPSIxNjAiIGhlaWdodD0iMzYiIHJ4PSI4IiBmaWxsPSJ0cmFuc3BhcmVudCIvPgogICAgPHJlY3QgeD0iMjgiIHk9IjI1NSIgd2lkdGg9Ijc1IiBoZWlnaHQ9IjEyIiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjQpIi8+CiAgPC9nPgogIDwhLS0gVXNlciBhdmF0YXIgYm90dG9tIC0tPgogIDxjaXJjbGUgY3g9IjI4IiBjeT0iNDcyIiByPSIxNiIgZmlsbD0iIzAwNzFFMyIvPgogIDxyZWN0IHg9IjU0IiB5PSI0NjQiIHdpZHRoPSI4MCIgaGVpZ2h0PSI4IiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjYpIi8+CiAgPHJlY3QgeD0iNTQiIHk9IjQ3NiIgd2lkdGg9IjU1IiBoZWlnaHQ9IjciIHJ4PSIzIiBmaWxsPSJyZ2JhKDI1NSwyNTUsMjU1LDAuMykiLz4KICA8IS0tIE1haW4gY29udGVudCAtLT4KICA8IS0tIEhlYWRlciAtLT4KICA8cmVjdCB4PSIxODAiIHk9IjAiIHdpZHRoPSI2MjAiIGhlaWdodD0iNTYiIGZpbGw9IiNmZmYiIC8+CiAgPHJlY3QgeD0iMTgwIiB5PSI1NiIgd2lkdGg9IjYyMCIgaGVpZ2h0PSIxIiBmaWxsPSIjRThFOEVEIi8+CiAgPHJlY3QgeD0iMjAwIiB5PSIxNiIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxMiIgcng9IjQiIGZpbGw9IiMxRDFEMUYiLz4KICA8cmVjdCB4PSIyMDAiIHk9IjM0IiB3aWR0aD0iMTAwIiBoZWlnaHQ9IjkiIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgPCEtLSBIZWFkZXIgcmlnaHQgYWN0aW9ucyAtLT4KICA8Y2lyY2xlIGN4PSI3MzAiIGN5PSIyOCIgcj0iMTYiIGZpbGw9IiNGNUY1RjciLz4KICA8cmVjdCB4PSI3MjAiIHk9IjIzIiB3aWR0aD0iMjAiIGhlaWdodD0iMTAiIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgPGNpcmNsZSBjeD0iNzY4IiBjeT0iMjgiIHI9IjE2IiBmaWxsPSIjMDA3MUUzIi8+CiAgPHJlY3QgeD0iNzU4IiB5PSIyMyIgd2lkdGg9IjIwIiBoZWlnaHQ9IjEwIiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjgpIi8+CiAgPCEtLSBNZXRyaWMgY2FyZHMgcm93IC0tPgogIDxnPgogICAgPCEtLSBDYXJkIDEgLS0+CiAgICA8cmVjdCB4PSIxOTYiIHk9IjcyIiB3aWR0aD0iMTM1IiBoZWlnaHQ9IjkwIiByeD0iMTIiIGZpbGw9IiNmZmYiIHN0cm9rZT0iI0U4RThFRCIvPgogICAgPHJlY3QgeD0iMjEwIiB5PSI4NiIgd2lkdGg9IjYwIiBoZWlnaHQ9IjgiIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgICA8cmVjdCB4PSIyMTAiIHk9IjEwMiIgd2lkdGg9IjkwIiBoZWlnaHQ9IjE4IiByeD0iNCIgZmlsbD0iIzFEMUQxRiIvPgogICAgPHJlY3QgeD0iMjEwIiB5PSIxMjgiIHdpZHRoPSI1MCIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iIzM0Qzc1OSIvPgogICAgPCEtLSBDYXJkIDIgLS0+CiAgICA8cmVjdCB4PSIzMzkiIHk9IjcyIiB3aWR0aD0iMTM1IiBoZWlnaHQ9IjkwIiByeD0iMTIiIGZpbGw9IiNmZmYiIHN0cm9rZT0iI0U4RThFRCIvPgogICAgPHJlY3QgeD0iMzUzIiB5PSI4NiIgd2lkdGg9IjY1IiBoZWlnaHQ9IjgiIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgICA8cmVjdCB4PSIzNTMiIHk9IjEwMiIgd2lkdGg9Ijg1IiBoZWlnaHQ9IjE4IiByeD0iNCIgZmlsbD0iIzFEMUQxRiIvPgogICAgPHJlY3QgeD0iMzUzIiB5PSIxMjgiIHdpZHRoPSI1NSIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iIzAwNzFFMyIvPgogICAgPCEtLSBDYXJkIDMgLS0+CiAgICA8cmVjdCB4PSI0ODIiIHk9IjcyIiB3aWR0aD0iMTM1IiBoZWlnaHQ9IjkwIiByeD0iMTIiIGZpbGw9IiNmZmYiIHN0cm9rZT0iI0U4RThFRCIvPgogICAgPHJlY3QgeD0iNDk2IiB5PSI4NiIgd2lkdGg9IjU1IiBoZWlnaHQ9IjgiIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgICA8cmVjdCB4PSI0OTYiIHk9IjEwMiIgd2lkdGg9Ijk1IiBoZWlnaHQ9IjE4IiByeD0iNCIgZmlsbD0iIzFEMUQxRiIvPgogICAgPHJlY3QgeD0iNDk2IiB5PSIxMjgiIHdpZHRoPSI0NSIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iI0ZGOUYwQSIvPgogICAgPCEtLSBDYXJkIDQgLS0+CiAgICA8cmVjdCB4PSI2MjUiIHk9IjcyIiB3aWR0aD0iMTM1IiBoZWlnaHQ9IjkwIiByeD0iMTIiIGZpbGw9IiMwMDcxRTMiLz4KICAgIDxyZWN0IHg9IjYzOSIgeT0iODYiIHdpZHRoPSI2MCIgaGVpZ2h0PSI4IiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjcpIi8+CiAgICA8cmVjdCB4PSI2MzkiIHk9IjEwMiIgd2lkdGg9Ijg4IiBoZWlnaHQ9IjE4IiByeD0iNCIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjk1KSIvPgogICAgPHJlY3QgeD0iNjM5IiB5PSIxMjgiIHdpZHRoPSI1MiIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjcpIi8+CiAgPC9nPgogIDwhLS0gQ2hhcnQgYXJlYSAtLT4KICA8cmVjdCB4PSIxOTYiIHk9IjE3NCIgd2lkdGg9IjQyMCIgaGVpZ2h0PSIyMDAiIHJ4PSIxMiIgZmlsbD0iI2ZmZiIgc3Ryb2tlPSIjRThFOEVEIi8+CiAgPHJlY3QgeD0iMjE0IiB5PSIxODgiIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAiIHJ4PSI0IiBmaWxsPSIjMUQxRDFGIi8+CiAgPHJlY3QgeD0iMjE0IiB5PSIyMDQiIHdpZHRoPSI3MCIgaGVpZ2h0PSI4IiByeD0iMyIgZmlsbD0iI0FFQUVCMiIvPgogIDwhLS0gQmFyIGNoYXJ0IC0tPgogIDxnPgogICAgPHJlY3QgeD0iMjI1IiB5PSIzMTAiIHdpZHRoPSIyOCIgaGVpZ2h0PSI1NSIgcng9IjQiIGZpbGw9IiNFOEYwRkQiLz4KICAgIDxyZWN0IHg9IjI2NSIgeT0iMjkwIiB3aWR0aD0iMjgiIGhlaWdodD0iNzUiIHJ4PSI0IiBmaWxsPSIjRThGMEZEIi8+CiAgICA8cmVjdCB4PSIzMDUiIHk9IjI3NSIgd2lkdGg9IjI4IiBoZWlnaHQ9IjkwIiByeD0iNCIgZmlsbD0iIzAwNzFFMyIgb3BhY2l0eT0iMC42Ii8+CiAgICA8cmVjdCB4PSIzNDUiIHk9IjI2MCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjEwNSIgcng9IjQiIGZpbGw9IiMwMDcxRTMiIG9wYWNpdHk9IjAuNzUiLz4KICAgIDxyZWN0IHg9IjM4NSIgeT0iMjQ1IiB3aWR0aD0iMjgiIGhlaWdodD0iMTIwIiByeD0iNCIgZmlsbD0iIzAwNzFFMyIvPgogICAgPHJlY3QgeD0iNDI1IiB5PSIyNTUiIHdpZHRoPSIyOCIgaGVpZ2h0PSIxMTAiIHJ4PSI0IiBmaWxsPSIjMDA3MUUzIiBvcGFjaXR5PSIwLjg1Ii8+CiAgICA8cmVjdCB4PSI0NjUiIHk9IjI3MCIgd2lkdGg9IjI4IiBoZWlnaHQ9Ijk1IiByeD0iNCIgZmlsbD0iIzAwNzFFMyIgb3BhY2l0eT0iMC43Ii8+CiAgICA8cmVjdCB4PSI1MDUiIHk9IjI1OCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjEwNyIgcng9IjQiIGZpbGw9IiMwMDcxRTMiIG9wYWNpdHk9IjAuOCIvPgogICAgPHJlY3QgeD0iNTQ1IiB5PSIyNDIiIHdpZHRoPSIyOCIgaGVpZ2h0PSIxMjMiIHJ4PSI0IiBmaWxsPSIjMzRDNzU5Ii8+CiAgPC9nPgogIDwhLS0gWCBheGlzIGxhYmVscyAtLT4KICA8ZyBmaWxsPSIjQUVBRUIyIiBmb250LWZhbWlseT0ic3lzdGVtLXVpIiBmb250LXNpemU9IjkiPgogICAgPHRleHQgeD0iMjMyIiB5PSIzODAiPlQxPC90ZXh0Pjx0ZXh0IHg9IjI3MiIgeT0iMzgwIj5UMjwvdGV4dD48dGV4dCB4PSIzMTIiIHk9IjM4MCI+VDM8L3RleHQ+CiAgICA8dGV4dCB4PSIzNTIiIHk9IjM4MCI+VDQ8L3RleHQ+PHRleHQgeD0iMzkyIiB5PSIzODAiPlQ1PC90ZXh0Pjx0ZXh0IHg9IjQzMiIgeT0iMzgwIj5UNjwvdGV4dD4KICAgIDx0ZXh0IHg9IjQ3MiIgeT0iMzgwIj5UNzwvdGV4dD48dGV4dCB4PSI1MTIiIHk9IjM4MCI+VDg8L3RleHQ+PHRleHQgeD0iNTUyIiB5PSIzODAiPlQ5PC90ZXh0PgogIDwvZz4KICA8IS0tIFJpZ2h0IHBhbmVsOiBpbnZlbnRvcnkgbGlzdCAtLT4KICA8cmVjdCB4PSI2MjUiIHk9IjE3NCIgd2lkdGg9IjEzNSIgaGVpZ2h0PSIyMDAiIHJ4PSIxMiIgZmlsbD0iI2ZmZiIgc3Ryb2tlPSIjRThFOEVEIi8+CiAgPHJlY3QgeD0iNjM5IiB5PSIxODgiIHdpZHRoPSI5MCIgaGVpZ2h0PSI5IiByeD0iMyIgZmlsbD0iIzFEMUQxRiIvPgogIDxnPgogICAgPHJlY3QgeD0iNjM5IiB5PSIyMTAiIHdpZHRoPSIxMDciIGhlaWdodD0iMzAiIHJ4PSI2IiBmaWxsPSIjRjVGNUY3Ii8+CiAgICA8cmVjdCB4PSI2NDciIHk9IjIxOCIgd2lkdGg9IjYwIiBoZWlnaHQ9IjciIHJ4PSIyIiBmaWxsPSIjM0EzQTNDIi8+CiAgICA8cmVjdCB4PSI2NDciIHk9IjIyOSIgd2lkdGg9IjQwIiBoZWlnaHQ9IjUiIHJ4PSIyIiBmaWxsPSIjQUVBRUIyIi8+CiAgICA8cmVjdCB4PSI3MTAiIHk9IjIxOCIgd2lkdGg9IjI4IiBoZWlnaHQ9IjE0IiByeD0iNCIgZmlsbD0icmdiYSg1MiwxOTksODksMC4xNSkiLz4KICAgIDxyZWN0IHg9IjcxNCIgeT0iMjIyIiB3aWR0aD0iMjAiIGhlaWdodD0iNiIgcng9IjIiIGZpbGw9IiMzNEM3NTkiLz4KICAgIAogICAgPHJlY3QgeD0iNjM5IiB5PSIyNDgiIHdpZHRoPSIxMDciIGhlaWdodD0iMzAiIHJ4PSI2IiBmaWxsPSIjRjVGNUY3Ii8+CiAgICA8cmVjdCB4PSI2NDciIHk9IjI1NiIgd2lkdGg9IjU1IiBoZWlnaHQ9IjciIHJ4PSIyIiBmaWxsPSIjM0EzQTNDIi8+CiAgICA8cmVjdCB4PSI2NDciIHk9IjI2NyIgd2lkdGg9IjQ1IiBoZWlnaHQ9IjUiIHJ4PSIyIiBmaWxsPSIjQUVBRUIyIi8+CiAgICA8cmVjdCB4PSI3MTAiIHk9IjI1NiIgd2lkdGg9IjI4IiBoZWlnaHQ9IjE0IiByeD0iNCIgZmlsbD0icmdiYSgyNTUsMTU5LDEwLDAuMTUpIi8+CiAgICA8cmVjdCB4PSI3MTQiIHk9IjI2MCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjYiIHJ4PSIyIiBmaWxsPSIjRkY5RjBBIi8+CiAgICAKICAgIDxyZWN0IHg9IjYzOSIgeT0iMjg2IiB3aWR0aD0iMTA3IiBoZWlnaHQ9IjMwIiByeD0iNiIgZmlsbD0iI0Y1RjVGNyIvPgogICAgPHJlY3QgeD0iNjQ3IiB5PSIyOTQiIHdpZHRoPSI2NSIgaGVpZ2h0PSI3IiByeD0iMiIgZmlsbD0iIzNBM0EzQyIvPgogICAgPHJlY3QgeD0iNjQ3IiB5PSIzMDUiIHdpZHRoPSIzNSIgaGVpZ2h0PSI1IiByeD0iMiIgZmlsbD0iI0FFQUVCMiIvPgogICAgPHJlY3QgeD0iNzEwIiB5PSIyOTQiIHdpZHRoPSIyOCIgaGVpZ2h0PSIxNCIgcng9IjQiIGZpbGw9InJnYmEoNTIsMTk5LDg5LDAuMTUpIi8+CiAgICA8cmVjdCB4PSI3MTQiIHk9IjI5OCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjYiIHJ4PSIyIiBmaWxsPSIjMzRDNzU5Ii8+CiAgICAKICAgIDxyZWN0IHg9IjYzOSIgeT0iMzI0IiB3aWR0aD0iMTA3IiBoZWlnaHQ9IjMwIiByeD0iNiIgZmlsbD0iI0Y1RjVGNyIvPgogICAgPHJlY3QgeD0iNjQ3IiB5PSIzMzIiIHdpZHRoPSI1MCIgaGVpZ2h0PSI3IiByeD0iMiIgZmlsbD0iIzNBM0EzQyIvPgogICAgPHJlY3QgeD0iNjQ3IiB5PSIzNDMiIHdpZHRoPSI0MiIgaGVpZ2h0PSI1IiByeD0iMiIgZmlsbD0iI0FFQUVCMiIvPgogICAgPHJlY3QgeD0iNzEwIiB5PSIzMzIiIHdpZHRoPSIyOCIgaGVpZ2h0PSIxNCIgcng9IjQiIGZpbGw9InJnYmEoMjU1LDU5LDQ4LDAuMTIpIi8+CiAgICA8cmVjdCB4PSI3MTQiIHk9IjMzNiIgd2lkdGg9IjIwIiBoZWlnaHQ9IjYiIHJ4PSIyIiBmaWxsPSIjRkYzQjMwIi8+CiAgPC9nPgogIDwhLS0gQm90dG9tIHRhYmxlIC0tPgogIDxyZWN0IHg9IjE5NiIgeT0iMzg0IiB3aWR0aD0iNTY0IiBoZWlnaHQ9IjEwMCIgcng9IjEyIiBmaWxsPSIjZmZmIiBzdHJva2U9IiNFOEU4RUQiLz4KICA8cmVjdCB4PSIyMTQiIHk9IjM5NiIgd2lkdGg9IjgwIiBoZWlnaHQ9IjkiIHJ4PSIzIiBmaWxsPSIjMUQxRDFGIi8+CiAgPCEtLSBUYWJsZSByb3dzIC0tPgogIDxnPgogICAgPHJlY3QgeD0iMjE0IiB5PSI0MTgiIHdpZHRoPSI1MjgiIGhlaWdodD0iMSIgZmlsbD0iI0U4RThFRCIvPgogICAgPHJlY3QgeD0iMjE0IiB5PSI0MTgiIHdpZHRoPSIxNjAiIGhlaWdodD0iMjUiIGZpbGw9InRyYW5zcGFyZW50Ii8+CiAgICA8cmVjdCB4PSIyMjAiIHk9IjQyNSIgd2lkdGg9IjEwMCIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iIzNBM0EzQyIvPgogICAgPHJlY3QgeD0iNDIwIiB5PSI0MjUiIHdpZHRoPSI2MCIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iIzM0Qzc1OSIvPgogICAgPHJlY3QgeD0iNTYwIiB5PSI0MjUiIHdpZHRoPSI3MCIgaGVpZ2h0PSI3IiByeD0iMyIgZmlsbD0iI0FFQUVCMiIvPgogICAgCiAgICA8cmVjdCB4PSIyMTQiIHk9IjQ0MyIgd2lkdGg9IjUyOCIgaGVpZ2h0PSIxIiBmaWxsPSIjRThFOEVEIi8+CiAgICA8cmVjdCB4PSIyMjAiIHk9IjQ1MCIgd2lkdGg9Ijg1IiBoZWlnaHQ9IjciIHJ4PSIzIiBmaWxsPSIjM0EzQTNDIi8+CiAgICA8cmVjdCB4PSI0MjAiIHk9IjQ1MCIgd2lkdGg9IjU1IiBoZWlnaHQ9IjciIHJ4PSIzIiBmaWxsPSIjRkY5RjBBIi8+CiAgICA8cmVjdCB4PSI1NjAiIHk9IjQ1MCIgd2lkdGg9IjY1IiBoZWlnaHQ9IjciIHJ4PSIzIiBmaWxsPSIjQUVBRUIyIi8+CiAgICAKICAgIDxyZWN0IHg9IjIyMCIgeT0iNDc1IiB3aWR0aD0iOTUiIGhlaWdodD0iNyIgcng9IjMiIGZpbGw9IiMzQTNBM0MiLz4KICAgIDxyZWN0IHg9IjQyMCIgeT0iNDc1IiB3aWR0aD0iNjUiIGhlaWdodD0iNyIgcng9IjMiIGZpbGw9IiMzNEM3NTkiLz4KICAgIDxyZWN0IHg9IjU2MCIgeT0iNDc1IiB3aWR0aD0iNzUiIGhlaWdodD0iNyIgcng9IjMiIGZpbGw9IiNBRUFFQjIiLz4KICA8L2c+CiAgPHRleHQgeD0iMjAwIiB5PSI0OTciIGZvbnQtZmFtaWx5PSJzeXN0ZW0tdWksc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxMSIgZmlsbD0icmdiYSgwLDAsMCwwLjIpIiBmb250LXdlaWdodD0iNjAwIj5PT0hYIMK3IEFnZW5jeSBEYXNoYm9hcmQ8L3RleHQ+Cjwvc3ZnPg==" alt="OOHX App">
        </div>
      </div>
    </div>
  </div>
</section>

<div class="trust">
  <div class="trust-in">
    <div class="trust-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:18px;height:18px;flex-shrink:0"><path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z"/></svg>Verified inventory</div>
    <div class="trust-sep"></div>
    <div class="trust-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:18px;height:18px;flex-shrink:0"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>Traffic real-time</div>
    <div class="trust-sep"></div>
    <div class="trust-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:18px;height:18px;flex-shrink:0"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z"/></svg>AI-powered search</div>
    <div class="trust-sep"></div>
    <div class="trust-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:18px;height:18px;flex-shrink:0"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>Secure payment</div>
    <div class="trust-sep"></div>
    <div class="trust-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:18px;height:18px;flex-shrink:0"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>Agency support 24/7</div>
  </div>
</div>

<section class="sec rv">
  <div class="w">
    <div class="sec-head">
      <div class="sec-head-row">
        <div>
          <span class="eyebrow">LOẠI ĐỊA ĐIỂM</span>
          <h2 class="section-hed">Duyệt theo Địa điểm</h2>
          <p class="section-sub" style="margin-top:10px;font-size:16px">Outdoor, indoor, digital hay static — tất cả trên một nền tảng.</p>
        </div>
        <a href="{{ route('fp.listing') }}" class="btn btn-s btn-sm" style="margin-top:6px;flex-shrink:0">Tất cả <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t2)" style="width:14px;height:14px;flex-shrink:0"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></a>
      </div>
    </div>
    <div class="cat-grid">
      @foreach($venueTypes->take(5) as $vt)
      <a href="/explore?venue_type[]={{ $vt['type'] }}" class="cat-card">
        <div class="cat-img">
          @if($vt['thumb'])<img src="{{ $vt['thumb'] }}" alt="{{ $vt['label'] }}" loading="lazy">@endif
          <div class="cat-icon"><span class="material-symbols-outlined">{{ $vt['icon'] ?? 'tv' }}</span></div>
        </div>
        <div class="cat-body">
          <div class="cat-name">{{ $vt['label'] }}</div>
          <div class="cat-meta">{{ $vt['count'] }} vị trí <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></div>
        </div>
      </a>
      @endforeach
    </div>
  </div>
</section>

<section class="sec sec-gray rv">
  <div class="w">
    <div class="sec-head">
      <div class="sec-head-row">
        <div>
          <span class="eyebrow">MARKET COVERAGE</span>
          <h2 class="section-hed">{{ $stats['total_cities'] }} Tỉnh thành</h2>
          <p class="section-sub" style="margin-top:10px;font-size:16px">Chọn thị trường để bắt đầu lên plan.</p>
        </div>
        <a href="{{ route('fp.listing') }}" class="btn btn-s btn-sm" style="margin-top:6px;flex-shrink:0">All markets <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t2)" style="width:14px;height:14px;flex-shrink:0"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></a>
      </div>
    </div>
    @php
      $cityGradients = [
          ['#1C3A5E','#2A4FF6'], ['#1A3A2A','#34C759'], ['#3A1A3A','#AF52DE'],
          ['#3A1C1A','#FF3B30'], ['#1A2A3A','#0071E3'], ['#1D1D1F','#3A3A3C'],
          ['#2D1B4E','#7C3AED'], ['#1A3A3A','#06B6D4'],
      ];
    @endphp
    <div class="city-grid">
      @foreach($topCities as $i => $city)
      @php $cg = $cityGradients[$i % count($cityGradients)]; @endphp
      <a href="/explore?city[]={{ $city['code'] }}" class="city-card">
        <div class="city-bg" style="background:linear-gradient(135deg,{{ $cg[0] }},{{ $cg[1] }})">
          @if(!empty($city['photo']))
          <img src="{{ $city['photo'] }}" alt="{{ $city['name'] }}" loading="lazy">
          @endif
        </div>
        <div class="city-info">
          <div class="city-name">{{ $city['name'] }}</div>
          <div class="city-count">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
            {{ $city['count'] }} vị trí
          </div>
        </div>
      </a>
      @endforeach
    </div>
  </div>
</section>

<section class="sec rv">
  <div class="w">
      <div class="sec-head">
        <div class="sec-head-row">
          <div>
            <span class="eyebrow">PREMIUM POSITIONS</span>
            <h2 class="section-hed">Đang còn trống</h2>
            <p class="section-sub" style="margin-top:10px;font-size:16px">Ảnh thực địa · Traffic data xác thực · Đặt booking nhanh</p>
          </div>
          <a href="{{ route('fp.listing') }}" class="btn btn-s btn-sm" style="flex-shrink:0;align-self:flex-end">Xem tất cả <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t2)" style="width:14px;height:14px;flex-shrink:0"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></a>
        </div>
      </div>
    <div class="hs" id="premium-slider">
      @forelse($featuredScreens as $screen)
          @include('frontpage.partials.screen-card', ['screen' => $screen])
      @empty
          <div style="padding:40px;text-align:center;color:var(--t4)">Chưa có inventory</div>
      @endforelse
    </div>
    <div class="hs-dots" id="premium-dots"></div>
  </div>
</section>

<section class="sec sec-gray rv">
  <div class="w">
    <div class="sec-head">
      <div class="sec-head-row">
        <div>
          <span class="eyebrow">GEO INTELLIGENCE</span>
          <h2 class="section-hed">Live Map View</h2>
          <p class="section-sub" style="margin-top:10px;font-size:16px">OOH là business của vị trí — xem thực tế trên bản đồ.</p>
        </div>
        <a href="{{ route('fp.map') }}" class="btn btn-p btn-sm" style="margin-top:6px;flex-shrink:0"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px;flex-shrink:0"><path d="M21 11V3h-8l3.29 3.29-10 10L3 13v8h8l-3.29-3.29 10-10z"/></svg> Open map</a>
      </div>
    </div>
    <div class="map-wrap">
      <div class="map-mock" id="hp-map-container">
        <div id="hp-leaflet-map" style="position:absolute;inset:0;z-index:0"></div>

        {{-- City picker bar --}}
        <div class="hp-map-city-bar" id="hp-city-bar">
          @php $cs = $mapData['citySlug'] ?? 'hanoi'; @endphp
          <button class="hmc-btn{{ $cs === 'all' ? ' on' : '' }}" data-city="all">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;flex-shrink:0"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg> Toàn quốc
          </button>
          <button class="hmc-btn{{ $cs === 'hanoi' ? ' on' : '' }}" data-city="hanoi">Hà Nội</button>
          <button class="hmc-btn{{ $cs === 'hcm' ? ' on' : '' }}" data-city="hcm">TP.HCM</button>
          <button class="hmc-btn{{ $cs === 'danang' ? ' on' : '' }}" data-city="danang">Đà Nẵng</button>
          {{-- More cities dropdown --}}
          <div class="hmc-more" id="hmc-more">
            <button class="hmc-btn hmc-more-trigger" id="hmc-more-btn">
              Khác <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;flex-shrink:0"><path d="M7 10l5 5 5-5z"/></svg>
            </button>
            <div class="hmc-dropdown" id="hmc-dropdown">
              <div class="hmc-dd-head">Chọn tỉnh/thành</div>
              <div class="hmc-dd-list">
                @foreach($topCities as $city)
                  @if(!in_array($city['code'], ['hanoi', 'hcm', 'danang']))
                  <button class="hmc-dd-item{{ $cs === $city['code'] ? ' on' : '' }}" data-city="{{ $city['code'] }}">
                    {{ $city['name'] }} <span class="hmc-dd-count">{{ number_format($city['count']) }}</span>
                  </button>
                  @endif
                @endforeach
              </div>
            </div>
          </div>
        </div>

        {{-- Zoom controls --}}
        <div class="hp-map-zoom">
          <button class="hm-zoom-btn" id="hp-zoom-in">+</button>
          <button class="hm-zoom-btn" id="hp-zoom-out">−</button>
        </div>

        {{-- Popup (shared partial with hp- prefix) --}}
        @include('frontpage.partials.map-popup', ['pfx' => 'hp-'])
      </div>

      <div class="map-bar">
        <div class="map-count"><strong id="hp-map-total">{{ number_format($mapData['total'] ?? 0) }}</strong> màn hình tại <span id="hp-map-city-label">{{ $mapData['cityName'] ?? 'Hà Nội' }}</span></div>
        <a href="{{ route('fp.map') }}" class="btn btn-s btn-xs" style="flex-shrink:0;border-radius:8px">Xem tất cả trên bản đồ →</a>
      </div>
    </div>
  </div>
</section>

<section class="sec rv">
  <div class="w">
    <div class="sec-head">
      <div class="sec-head-row">
        <div>
          <span class="eyebrow">VERIFIED PARTNERS</span>
          <h2 class="section-hed">Trusted Media Owners</h2>
          <p class="section-sub" style="margin-top:10px;font-size:16px">Chỉ làm việc với owner đã verified — inventory thật, data thật.</p>
        </div>
        <a href="{{ route('fp.owners') }}" class="btn btn-s btn-sm" style="margin-top:6px;flex-shrink:0">All owners <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--t2)" style="width:14px;height:14px;flex-shrink:0"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg></a>
      </div>
    </div>
    <div class="oc-grid">
    @foreach($featuredOwners as $owner)
        @include('frontpage.partials.owner-card', ['owner' => $owner, 'variant' => 'compact'])
    @endforeach
    </div>
      </div>
</section>

<section class="sec sec-gray rv">
  <div class="w">
    <div style="text-align:center;max-width:560px;margin:0 auto 40px">
      <span class="eyebrow">WORKFLOW</span>
      <h2 class="section-hed">4 bước đặt booking OOH</h2>
      <p class="section-sub" style="margin:12px auto 0;font-size:16px">Không email qua lại. Tìm, kiểm tra, đặt và theo dõi trực tiếp.</p>
    </div>
    <div class="steps-grid" style="background:#fff;border-radius:var(--r4);border:1px solid var(--ln2);overflow:hidden;box-shadow:var(--sh2)">
      <div class="step-card rv"><div class="step-num" style="background:var(--bl)">01</div><div class="step-title">Tìm kiếm &amp; lọc</div><div class="step-desc">AI search theo khu vực, loại biển, budget và availability. Map view tức thì.</div></div><div class="step-card rv"><div class="step-num" style="background:#34C759">02</div><div class="step-title">So sánh</div><div class="step-desc">Ảnh thực địa, traffic data, availability calendar. So sánh tối đa 5 vị trí.</div></div><div class="step-card rv"><div class="step-num" style="background:#FF9F0A">03</div><div class="step-title">Gửi booking</div><div class="step-desc">Điền thông tin campaign, upload creative. Media owner nhận ngay, xử lý nhanh chóng.</div></div><div class="step-card rv"><div class="step-num" style="background:#AF52DE">04</div><div class="step-title">Theo dõi &amp; báo cáo</div><div class="step-desc">Dashboard theo dõi booking status + proof-of-play report tự động.</div></div>
    </div>
  </div>
</section>

<section class="sec rv">
  <div class="w">
    <div class="value-grid">
      <div>
        <span class="eyebrow">AI-POWERED PLATFORM</span>
        <h2 class="section-hed" style="margin-top:8px;margin-bottom:12px">Marketplace OOH đầu tiên có AI</h2>
        <p class="section-sub" style="margin-bottom:32px;font-size:16px">Kết nối agency, brand và media owner — nhanh hơn, minh bạch hơn, đo được kết quả.</p>
        <div class="ai-cards"><div class="ai-card rv"><div class="ai-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:20px;height:20px;flex-shrink:0"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z"/></svg></div><div><div class="ai-card-title">AI Campaign Match</div><div class="ai-card-desc">Mô tả campaign bằng ngôn ngữ tự nhiên — AI đề xuất inventory phù hợp nhất trong giây.</div><div class="ai-card-tag"><span class="badge badge-bl">GPT-powered</span></div></div></div><div class="ai-card rv"><div class="ai-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:20px;height:20px;flex-shrink:0"><path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/></svg></div><div><div class="ai-card-title">Real-time Availability</div><div class="ai-card-desc">Kiểm tra slot trống trước khi brief AdOps. Sync với owner trong 5 phút.</div><div class="ai-card-tag"><span class="badge badge-bl">Live sync &lt;5min</span></div></div></div><div class="ai-card rv"><div class="ai-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:20px;height:20px;flex-shrink:0"><path d="M20.38 8.57l-1.23 1.85a8 8 0 0 1-.22 7.58H5.07A8 8 0 0 1 15.58 6.85l1.85-1.23A10 10 0 0 0 3.35 19a2 2 0 0 0 1.72 1h13.85a2 2 0 0 0 1.74-1 10 10 0 0 0-.27-10.44zm-9.79 6.84a2 2 0 0 0 2.83 0l5.66-8.49-8.49 5.66a2 2 0 0 0 0 2.83z"/></svg></div><div><div class="ai-card-title">AI Audience Estimation</div><div class="ai-card-desc">Ước tính reach từ traffic data thực tế, thời điểm trong ngày và demographic khu vực.</div><div class="ai-card-tag"><span class="badge badge-bl">Data-driven ±8%</span></div></div></div><div class="ai-card rv"><div class="ai-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--bl)" style="width:20px;height:20px;flex-shrink:0"><path d="M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z"/></svg></div><div><div class="ai-card-title">Verified Data Network</div><div class="ai-card-desc">Ảnh thực địa, location verified, traffic data nguồn đáng tin. Không có inventory ảo.</div><div class="ai-card-tag"><span class="badge badge-bl">100% verified</span></div></div></div></div>
      </div>
      <div>
        <div class="value-visual">
          <img src="https://images.unsplash.com/photo-1551836022-deb4988cc6c0?w=800&q=80&auto=format&fit=crop" loading="lazy">
          <div class="value-visual-ov">
            <div class="vv-number">3.2×</div>
            <div class="vv-label">Nhanh hơn cách đặt truyền thống</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="sec sec-gray rv">
  <div class="w">
    <div style="text-align:center;max-width:560px;margin:0 auto 40px">
      <span class="eyebrow">GÓI DỊCH VỤ</span>
      <h2 class="section-hed">Gói chiến dịch</h2>
      <p class="section-sub" style="margin:12px auto 0;font-size:16px">Chưa biết bắt đầu từ đâu? OOHX tư vấn inventory phù hợp nhất.</p>
    </div>
    <div class="pkg-grid"><div class="pkg rv"><div class="pkg-head" style="background:linear-gradient(135deg,#1C3A5E,#2A4FF6)"><div class="pkg-head-bg" style="background-image:url('https://images.unsplash.com/photo-1567721913486-6585f069b3b7?w=600&q=40')"></div><div class="pkg-ey">Brand awareness</div><div class="pkg-name">City Launch</div><div class="pkg-desc">Phủ sóng 3 TP lớn trong 1 tháng</div></div><div class="pkg-body"><ul class="pkg-items"><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>5–8 LED/Billboard premium</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>HN + HCM + Đà Nẵng</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>2M+ impressions estimate</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Weekly performance report</li></ul><div class="pkg-price"><span class="pkg-price-n">Từ 300M</span><span class="pkg-price-u">/ tháng</span></div><button class="btn btn-o" style="width:100%;justify-content:center;border-radius:var(--r2)">City Launch</button></div></div><div class="pkg--feat pkg rv"><div class="pkg-head" style="background:linear-gradient(135deg,#1A3A2A,#34C759)"><div class="pkg-head-bg" style="background-image:url('https://images.unsplash.com/photo-1519567770579-c2fc5436bcf8?w=600&q=40')"></div><div class="pkg-ey">FMCG / Retail</div><div class="pkg-name">Retail Activation</div><div class="pkg-desc">LCD mall network + in-store activation</div></div><div class="pkg-body"><ul class="pkg-items"><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>LCD mall 10–15 screens</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Digital standee khu POP</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>QR tracking tích hợp</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Footfall report từng điểm</li></ul><div class="pkg-price"><span class="pkg-price-n">Từ 120M</span><span class="pkg-price-u">/ tháng</span></div><button class="btn btn-p" style="width:100%;justify-content:center;border-radius:var(--r2)">Retail Activation</button></div></div><div class="pkg rv"><div class="pkg-head" style="background:linear-gradient(135deg,#3A1A3A,#AF52DE)"><div class="pkg-head-bg" style="background-image:url('https://images.unsplash.com/photo-1436491865332-7a61a109cc05?w=600&q=40')"></div><div class="pkg-ey">Event / Launch</div><div class="pkg-name">Event Blitz</div><div class="pkg-desc">Phủ sóng nhanh cho sự kiện ngắn ngày</div></div><div class="pkg-body"><ul class="pkg-items"><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>LED 5km quanh event</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Min 7 ngày booking</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Production support</li><li class="pkg-item"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="var(--grn)" style="width:16px;height:16px;flex-shrink:0"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>Proof-of-play video</li></ul><div class="pkg-price"><span class="pkg-price-n">Từ 45M</span><span class="pkg-price-u">/ 7 ngày</span></div><button class="btn btn-o" style="width:100%;justify-content:center;border-radius:var(--r2)">Event Blitz</button></div></div></div>
  </div>
</section>

<section class="sec rv">
  <div class="w">
    <div class="owner-cta">
      <div class="oc-mesh"></div>
      <div class="oc-in">
        <div>
          <div style="display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:980px;padding:5px 13px;font-size:12px;font-weight:700;color:rgba(255,255,255,.9);margin-bottom:18px">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="rgba(255,255,255,.9)" style="width:14px;height:14px;flex-shrink:0"><path d="M17 11c.34 0 .67.03 1 .08V9l-5-5H5c-1.11 0-2 .89-2 2v14c0 1.11.89 2 2 2h6.26c-.79-1.13-1.26-2.51-1.26-4 0-3.87 3.13-7 7-7zM13 4.5l4.5 4.5H13V4.5zM17 19v-3h-3v-2h3v-3l4 4-4 4z"/></svg> MEDIA OWNER
          </div>
          <div class="oc-title">Đưa inventory của bạn lên OOHX</div>
          <p class="oc-desc">Tiếp cận agency &amp; brand đang tìm inventory mỗi ngày. Quản lý availability và nhận booking từ một dashboard.</p>
          <div class="oc-actions">
            <button class="btn btn-w btn-lg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" style="width:18px;height:18px;flex-shrink:0"><path d="M17 11c.34 0 .67.03 1 .08V9l-5-5H5c-1.11 0-2 .89-2 2v14c0 1.11.89 2 2 2h6.26c-.79-1.13-1.26-2.51-1.26-4 0-3.87 3.13-7 7-7zM13 4.5l4.5 4.5H13V4.5zM17 19v-3h-3v-2h3v-3l4 4-4 4z"/></svg> Đăng ký làm chủ sở hữu</button>
            <button class="btn btn-w">Tìm hiểu thêm</button>
          </div>
        </div>
        <div class="oc-features">
          <div class="oc-feat"><div class="oc-feat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="rgba(255,255,255,.9)" style="width:18px;height:18px;flex-shrink:0"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg></div><div><div class="oc-feat-title">Đăng inventory nhanh chóng</div><div class="oc-feat-sub">Upload ảnh, thông số, vị trí trong vài phút</div></div></div>
          <div class="oc-feat"><div class="oc-feat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="rgba(255,255,255,.9)" style="width:18px;height:18px;flex-shrink:0"><path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/></svg></div><div><div class="oc-feat-title">Quản lý availability calendar</div><div class="oc-feat-sub">Cập nhật slot theo real-time</div></div></div>
          <div class="oc-feat"><div class="oc-feat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="rgba(255,255,255,.9)" style="width:18px;height:18px;flex-shrink:0"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg></div><div><div class="oc-feat-title">Nhận booking notification tức thì</div><div class="oc-feat-sub">Không bỏ lỡ bất kỳ lead nào</div></div></div>
          {{-- ĐÃ GỠ: thẻ "Tăng fill rate lên 40%".
               Fill rate là tỷ lệ thời lượng đã bán trên tổng thời lượng, nên muốn
               biết nó phải có dữ liệu phát sóng thật. Hiện CHƯA CÓ thiết bị
               player nào gửi dữ liệu vào `impression_logs`, nên không có cách
               nào tính ra 40% — cũng không có cách nào tính ra con số khác để
               thay vào. Con số viết cứng trong template và hứa một kết quả
               kinh doanh với media owner (audit F-15).

               Khi có dữ liệu thật: tính từ `impression_daily_rollups` so với
               thời lượng khả dụng, và nêu rõ kỳ tính. Đừng in một số trung
               bình toàn sàn như thể đó là cam kết cho từng owner. --}}
        </div>
      </div>
    </div>
  </div>
</section>

@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<script>
@include('frontpage.partials.map-shared-js')
</script>
<script>
(function(){
  {{-- ĐÃ GỠ: bộ đếm impression sinh số ngẫu nhiên phía trình duyệt. Thẻ hiển
       thị nó cũng đã gỡ; để lại script thì lần sau ai thêm lại phần tử #imp-n
       là con số bịa quay về ngay.

       Chú thích này dùng cú pháp Blade, KHÔNG dùng `//` của JavaScript: chú
       thích JS nằm trong <script> sẽ đi thẳng ra trang, và test canh "không
       còn tên hàm sinh số ngẫu nhiên trong HTML" bắt đúng chuyện đó. --}}

  // Save toggle
  document.querySelectorAll('.inv-save').forEach(b=>b.addEventListener('click',e=>{
    e.stopPropagation(); b.classList.toggle('on');
    b.querySelector('svg').style.fill = b.classList.contains('on') ? 'var(--red)' : 'var(--t3)';
  }));

  // ── HOMEPAGE MINI MAP ──────────────────────────────────────────────
  var CITY_CENTERS = {
    all:     {lat:16.0, lng:106.0, zoom:6, name:'Việt Nam'},
    hanoi:    {lat:21.0285, lng:105.8542, zoom:12, name:'Hà Nội'},
    hcm:     {lat:10.7769, lng:106.7009, zoom:12, name:'TP. Hồ Chí Minh'},
    danang:  {lat:16.0544, lng:108.2022, zoom:13, name:'Đà Nẵng'},
    haiphong:{lat:20.8449, lng:106.6881, zoom:13, name:'Hải Phòng'},
    cantho:  {lat:10.0452, lng:105.7469, zoom:13, name:'Cần Thơ'},
    nghean:  {lat:18.6733, lng:105.6922, zoom:12, name:'Nghệ An'},
    thanhhoa:{lat:19.8075, lng:105.7764, zoom:12, name:'Thanh Hóa'},
    binhduong:{lat:11.3254, lng:106.477, zoom:12, name:'Bình Dương'},
    dongnai: {lat:10.9453, lng:106.8243, zoom:12, name:'Đồng Nai'},
    quangninh:{lat:21.006, lng:107.2925, zoom:12, name:'Quảng Ninh'},
    hue:     {lat:16.4637, lng:107.5909, zoom:13, name:'Huế'},
    khanhhoa:{lat:12.2388, lng:109.1967, zoom:12, name:'Khánh Hòa'},
    bariavungtau:{lat:10.412, lng:107.136, zoom:12, name:'Bà Rịa - Vũng Tàu'},
  };

  var HP_PINS = @json($mapData['pins'] ?? []);
  var currentCity = @json($mapData['citySlug'] ?? 'hanoi');
  var cityInfo = CITY_CENTERS[currentCity] || CITY_CENTERS.hanoi;

  var hpMap = L.map('hp-leaflet-map', {
    zoomControl: false,
    attributionControl: false,
    dragging: true,
    scrollWheelZoom: false,
  }).setView([cityInfo.lat, cityInfo.lng], cityInfo.zoom);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:18}).addTo(hpMap);
  L.control.attribution({position:'bottomleft',prefix:false}).addAttribution('© <a href="https://openstreetmap.org">OSM</a>').addTo(hpMap);

  // ── MarkerClusterGroup for home map ──
  var hpClusterGroup = L.markerClusterGroup({
    maxClusterRadius: function(zoom) {
      if (zoom <= 7) return 120;
      if (zoom <= 10) return 80;
      if (zoom <= 12) return 50;
      return 30;
    },
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true,
    disableClusteringAtZoom: 16,
    chunkedLoading: true,
    iconCreateFunction: OOHXMap.createClusterIcon,
  });
  hpMap.addLayer(hpClusterGroup);

  function renderHpPins(pins, flyToCity) {
    hpClusterGroup.clearLayers();
    var markerArray = [];
    pins.forEach(function(pin) {
      var icon = OOHXMap.createPinIcon(pin);
      var m = L.marker([pin.lat, pin.lng], {icon: icon});
      m.on('click', function() { showHpPopup(pin); });
      markerArray.push(m);
    });
    hpClusterGroup.addLayers(markerArray);

    if (flyToCity) {
      var c = CITY_CENTERS[flyToCity] || CITY_CENTERS.hanoi;
      hpMap.setView([c.lat, c.lng], c.zoom);
    }
  }

  renderHpPins(HP_PINS, currentCity);

  // Popup — delegate to shared OOHXMap with 'hp-' prefix
  function showHpPopup(pin) {
    OOHXMap.showPopup(pin, 'hp-');
    hpMap.panTo([pin.lat, pin.lng]);
  }

  // ── Switch city (shared function) ──────────────────────────────────
  function switchCity(city) {
    // Update UI: highlight active button
    document.querySelectorAll('.hmc-btn:not(.hmc-more-trigger)').forEach(function(b){ b.classList.remove('on'); });
    document.querySelectorAll('.hmc-dd-item').forEach(function(b){ b.classList.remove('on'); });

    var mainBtn = document.querySelector('.hmc-btn[data-city="' + city + '"]');
    var ddItem  = document.querySelector('.hmc-dd-item[data-city="' + city + '"]');
    if (mainBtn) mainBtn.classList.add('on');
    if (ddItem) {
      ddItem.classList.add('on');
      // Show city name on the trigger button
      document.getElementById('hmc-more-btn').classList.add('on');
    } else {
      document.getElementById('hmc-more-btn').classList.remove('on');
    }

    document.getElementById('hp-popup').style.display = 'none';
    closeDropdown();

    // Save cookie
    document.cookie = 'oohx_city=' + city + ';path=/;max-age=' + (30*86400) + ';SameSite=Lax';
    currentCity = city;

    // Sync localStorage top cities with /map (swap: picked city to top)
    if (city !== 'all') {
      try {
        var saved = JSON.parse(localStorage.getItem(OOHXMap.TOP_CITY_STORAGE) || '[]');
        if (!Array.isArray(saved)) saved = [];
        saved = [city].concat(saved.filter(function(c){ return c !== city; })).slice(0, 10);
        localStorage.setItem(OOHXMap.TOP_CITY_STORAGE, JSON.stringify(saved));
      } catch (e) {}
    }

    // Fly to known city immediately (if available), then fetch pins
    var c = CITY_CENTERS[city];
    if (c) {
      hpMap.flyTo([c.lat, c.lng], c.zoom, {duration:0.6});
    }

    // Fetch new pins → fitBounds to actual pin locations
    fetch('/?_map_city=' + city, {headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(data){
        HP_PINS = data.pins || [];
        renderHpPins(HP_PINS, null);
        document.getElementById('hp-map-total').textContent = (data.total || 0).toLocaleString('vi-VN');
        document.getElementById('hp-map-city-label').textContent = data.cityName || '';

        // Zoom to fit actual pins — works for all cities including dropdown ones
        if (HP_PINS.length > 0) {
          var bounds = L.latLngBounds(HP_PINS.map(function(p){ return [p.lat, p.lng]; }));
          hpMap.flyToBounds(bounds, {padding:[40,40], maxZoom:14, duration:0.6});
        } else if (!c) {
          // No pins and unknown city — show Vietnam
          hpMap.flyTo([16.0, 106.0], 6, {duration:0.6});
        }
      })
      .catch(function(){
        // Fetch failed — if no known center, fallback
        if (!c) hpMap.flyTo([16.0, 106.0], 6, {duration:0.6});
      });
  }

  // Main city buttons (Toàn quốc, Hà Nội, HCM, Đà Nẵng)
  document.querySelectorAll('.hmc-btn:not(.hmc-more-trigger)').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (btn.dataset.city) switchCity(btn.dataset.city);
    });
  });

  // ── Dropdown for more cities ──────────────────────────────────────
  var dropdown = document.getElementById('hmc-dropdown');
  var moreBtn  = document.getElementById('hmc-more-btn');

  function closeDropdown() { dropdown.classList.remove('open'); }

  moreBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });

  document.querySelectorAll('.hmc-dd-item').forEach(function(item) {
    item.addEventListener('click', function(e) {
      e.stopPropagation();
      switchCity(item.dataset.city);
    });
  });

  // Close dropdown on outside click
  document.addEventListener('click', function(e) {
    if (!document.getElementById('hmc-more').contains(e.target)) closeDropdown();
  });

  // ── Zoom controls ──────────────────────────────────────────────────
  document.getElementById('hp-zoom-in').addEventListener('click', function(){ hpMap.zoomIn(); });
  document.getElementById('hp-zoom-out').addEventListener('click', function(){ hpMap.zoomOut(); });

  // ── GEOLOCATION DETECT (first visit only) ──────────────────────────
  if (!getCookie('oohx_city') && navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(pos) {
      var lat = pos.coords.latitude;
      var lng = pos.coords.longitude;
      var closest = 'hanoi';
      var minDist = Infinity;
      for (var key in CITY_CENTERS) {
        if (key === 'all') continue;
        var c = CITY_CENTERS[key];
        var d = Math.pow(lat - c.lat, 2) + Math.pow(lng - c.lng, 2);
        if (d < minDist) { minDist = d; closest = key; }
      }
      if (closest !== currentCity) switchCity(closest);
      document.cookie = 'oohx_city=' + closest + ';path=/;max-age=' + (30*86400) + ';SameSite=Lax';
    }, function(){
      document.cookie = 'oohx_city=hanoi;path=/;max-age=' + (30*86400) + ';SameSite=Lax';
    }, {timeout: 5000, maximumAge: 3600000});
  }

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? m[1] : null;
  }
})();

// ── MEGA SEARCH (unified component) ──
@include('frontpage.partials.mega-search-js')

// ── Slider dots (reusable) ──
(function(){
    function initDots(sliderId, dotsId) {
        var slider = document.getElementById(sliderId);
        var dotsEl = document.getElementById(dotsId);
        if (!slider || !dotsEl) return;

        var cards = slider.querySelectorAll('.sc-card,.inv-card');
        if (cards.length === 0) return;

        // Calculate how many "pages" of visible cards
        function getPages() {
            var sliderW = slider.offsetWidth;
            var cardW = cards[0].offsetWidth + 16; // card + gap
            var visible = Math.max(1, Math.floor(sliderW / cardW));
            return Math.ceil(cards.length / visible);
        }

        function renderDots() {
            var pages = getPages();
            dotsEl.innerHTML = '';
            for (var i = 0; i < pages; i++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'hs-dot' + (i === 0 ? ' on' : '');
                dot.dataset.page = i;
                dot.addEventListener('click', function() {
                    var pg = parseInt(this.dataset.page);
                    var cardW = cards[0].offsetWidth + 16;
                    var sliderW = slider.offsetWidth;
                    var visible = Math.max(1, Math.floor(sliderW / cardW));
                    slider.scrollTo({ left: pg * visible * cardW, behavior: 'smooth' });
                });
                dotsEl.appendChild(dot);
            }
        }

        // Update active dot on scroll
        slider.addEventListener('scroll', function() {
            var cardW = cards[0].offsetWidth + 16;
            var sliderW = slider.offsetWidth;
            var visible = Math.max(1, Math.floor(sliderW / cardW));
            var current = Math.round(slider.scrollLeft / (visible * cardW));
            dotsEl.querySelectorAll('.hs-dot').forEach(function(d, i) {
                d.classList.toggle('on', i === current);
            });
        }, { passive: true });

        renderDots();
        window.addEventListener('resize', renderDots);
    }

    initDots('premium-slider', 'premium-dots');
})();


</script>
@endpush
