@extends('frontpage.layouts.app', ['activeNav' => '', 'bodyClass' => ''])

@section('title', 'Upload Creative — {{ $campaign->name }} | OOHX')

@section('content')
<div class="w" style="padding-top:24px;padding-bottom:64px">

    <div class="wz-steps">
        <div class="wz-step done"><div class="wz-step-n">&#10003;</div><div class="wz-step-l">Thông tin</div></div>
        <div class="wz-step-line done"></div>
        <div class="wz-step on"><div class="wz-step-n">2</div><div class="wz-step-l">Nội dung quảng cáo</div></div>
        <div class="wz-step-line"></div>
        <div class="wz-step"><div class="wz-step-n">3</div><div class="wz-step-l">Xác nhận</div></div>
    </div>

    <h1 class="wz-title">Upload Creative</h1>
    <p class="wz-sub">{{ $campaign->name }} &middot; {{ $campaign->code }}</p>

    @if(session('success'))
    <div class="cart-alert">{{ session('success') }}</div>
    @endif

    <div class="wz-layout">
        <div class="wz-main">
            {{-- Upload form --}}
            <div class="wz-card">
                <div class="wz-card-title">Tải lên creative</div>
                <form method="POST" action="{{ route('buyer.booking.creative.upload', $campaign) }}" enctype="multipart/form-data" class="wz-upload-form">
                    @csrf
                    <div class="wz-field">
                        <label>Tên creative</label>
                        <input type="text" name="name" placeholder="VD: Banner 16:9 - Honda Civic">
                    </div>
                    <div class="wz-field">
                        <label>File (JPG, PNG, MP4, WebM — tối đa 50MB)</label>
                        <input type="file" name="file" accept="image/jpeg,image/png,video/mp4,video/webm" required class="wz-file-input">
                    </div>
                    @if($errors->any())
                    <div class="auth-error">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                    @endif
                    <button type="submit" class="btn btn-p btn-sm">
                        <svg viewBox="0 0 24 24" fill="#fff" style="width:14px;height:14px"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
                        Upload
                    </button>
                </form>
            </div>

            {{-- Uploaded creatives list --}}
            @if($creatives->isNotEmpty())
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Creatives đã upload ({{ $creatives->count() }})</div>
                @php
                    // Chữ từ mô hình, màu ở đây.
                    //
                    // Trước đây chỗ này là một biểu thức ba ngôi chỉ biết MỘT
                    // mã — `pending_review` — nên `approved` hiện ra
                    // `approved` và `rejected` hiện ra `rejected`, nguyên văn
                    // tiếng Anh, trên trang người mua nhìn ngay sau khi tải tệp
                    // lên. Bảng đầy đủ vẫn nằm ở Filament, nhưng đó là khu
                    // quản trị nội bộ: người mua không bao giờ thấy nó.
                    //
                    // Màu khớp khu quản trị (`warning`/`success`/`danger`) và
                    // khớp quy ước của chiến dịch: chờ → cam, duyệt → xanh,
                    // từ chối → đỏ. Bản cũ cho `rejected` màu xám, cùng màu với
                    // `pending_review` — hai nghĩa khác nhau một màu.
                    $nhanTrangThai = \App\Models\Creative::STATUS_LABELS;
                    $nhanLoai      = \App\Models\Creative::TYPE_LABELS;
                    $mauTrangThai  = [
                        \App\Models\Creative::STATUS_PENDING_REVIEW => 'b-org',
                        \App\Models\Creative::STATUS_APPROVED       => 'b-grn',
                        \App\Models\Creative::STATUS_REJECTED       => 'b-red',
                    ];
                @endphp
                <div class="wz-creative-list">
                    @foreach($creatives as $c)
                    <div class="wz-creative-item">
                        <div class="wz-creative-thumb">
                            @if($c->type === \App\Models\Creative::TYPE_IMAGE)
                            <img src="{{ $c->file_url }}" alt="{{ $c->name }}">
                            @else
                            <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--bg2)">
                                <svg viewBox="0 0 24 24" fill="var(--t4)" style="width:24px;height:24px"><path d="M8 5v14l11-7z"/></svg>
                            </div>
                            @endif
                        </div>
                        <div class="wz-creative-info">
                            <div style="font-weight:600;color:var(--t1)">{{ $c->name }}</div>
                            {{-- Chữ từ bảng, không `strtoupper`: `vast_tag` ra `VAST_TAG`. --}}
                            <div style="font-size:11px;color:var(--t4)">{{ $nhanLoai[$c->type] ?? $c->type }} &middot; {{ number_format($c->file_size / 1024) }} KB</div>
                        </div>
                        <span class="badge {{ $mauTrangThai[$c->status] ?? 'b-gray' }}" style="font-size:10px">{{ $nhanTrangThai[$c->status] ?? $c->status }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Screen specs reference --}}
            <div class="wz-card" style="margin-top:16px">
                <div class="wz-card-title">Kích thước màn hình tham khảo</div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--t3)">
                    @foreach($lines as $line)
                    @php $spec = $line->screen->spec; @endphp
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ln2)">
                        <span style="font-weight:600;color:var(--t1)">{{ $line->screen->name }}</span>
                        <span>{{ $spec?->width_px ?? '—' }} x {{ $spec?->height_px ?? '—' }} px</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="wz-sidebar">
            <div class="wz-actions-card">
                <div style="font-size:14px;font-weight:700;color:var(--t1);margin-bottom:12px">Bước tiếp theo</div>
                <p style="font-size:13px;color:var(--t3);margin-bottom:16px">Upload creative không bắt buộc. Bạn có thể upload sau khi campaign được duyệt.</p>
                <a href="{{ route('buyer.booking.review', $campaign) }}" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px">
                    Tiếp tục Review <svg viewBox="0 0 24 24" fill="#fff" style="width:16px;height:16px"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                </a>
                <a href="{{ route('buyer.booking.creative', $campaign) }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px;margin-top:8px">Bỏ qua, review sau</a>
            </div>
        </div>
    </div>
</div>
@endsection
