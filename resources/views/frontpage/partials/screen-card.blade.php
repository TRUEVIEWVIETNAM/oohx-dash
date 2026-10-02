{{-- Unified screen card partial
     Receives: $screen (Screen model with spec, inventory, owner, site, site.network, products relations)
     Optional: $vnCatLabels (array), $compact (bool — smaller card for similar/featured)
--}}
@php
    $photo = $screen->display_photo ?? 'https://placehold.co/600x400/F5F5F7/6E6E73?text=No+Photo';
    $price = $screen->inventory?->display_price ?? 0;
    // Đơn vị tiền lấy từ kho, không dán cứng: `floor_cpm_currency` có hàng USD
    // trong dữ liệu thật, và một màn hình 2,50 USD hiện ra là "2 đ" (Codex R39).
    $priceCurrency = $screen->inventory?->display_currency ?? 'VND';
    $priceDisplay = $priceCurrency === 'VND'
        ? number_format($price, 0, ',', '.')
        : number_format($price, 2, ',', '.');
    $priceUnit = $screen->inventory?->display_price_unit ?? (($screen->inventory?->io_rate_unit ?? 'month') === 'week' ? 'màn hình/tuần' : 'màn hình/tháng');
    $vnCatId = $screen->inventory?->vn_category_id;
    $venueLabel = ($vnCatLabels ?? [])[$vnCatId] ?? '—';
    $city = $screen->site?->city ?? '';
    $widthM = $screen->spec?->width_cm ? round($screen->spec->width_cm / 100, 1) : null;
    $heightM = $screen->spec?->height_cm ? round($screen->spec->height_cm / 100, 1) : null;
    $sizeDisplay = ($widthM && $heightM) ? "{$widthM}x{$heightM}m" : '—';
    $compact = $compact ?? false;
    $detailUrl = route('fp.detail', $screen->slug ?? $screen->uuid ?? $screen->id);
    // Product badge: lấy product đầu tiên nếu screen thuộc product
    $product = $screen->relationLoaded('products') ? $screen->products->first() : null;

    // Suất còn lại, do người gọi truyền vào theo lô (2 truy vấn cho cả trang).
    //
    // FAIL-CLOSED: người gọi không truyền thì KHÔNG hiện badge. Badge này trước
    // đây in vô điều kiện trên mọi thẻ ở trang chủ, trang danh sách, trang chi
    // tiết và trang owner — tức mời người mua vào cả những suất đã bán kín
    // (audit F-15). Thà không nói gì còn hơn nói một điều chưa kiểm được.
    $cardAvailability = ($availability ?? [])[$screen->id] ?? null;
@endphp
<a href="{{ $detailUrl }}" class="sc-card {{ $compact ? 'sc-compact' : '' }}">
    <div class="sc-photo">
        <img src="{{ $photo }}" loading="lazy" alt="{{ $screen->name }}">
        @if($cardAvailability !== null && $cardAvailability > 0)
        <div class="sc-badge">Còn trống</div>
        @endif
        @if($product)
        <div class="sc-product-badge">
            @if($product->total_units > 1)
                <svg viewBox="0 0 24 24" fill="currentColor" style="width:11px;height:11px"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                {{ $product->total_units }} vị trí
            @endif
            @if($product->allowsIndividual())
                · Mua lẻ được
            @endif
        </div>
        @endif
    </div>
    <div class="sc-body">
        {{-- Hierarchy breadcrumb --}}
        @unless($compact)
        @php
            $networkName = $screen->site?->network?->name;
            $siteName = $screen->site?->name;
        @endphp
        @if($networkName || $siteName)
        <div class="sc-breadcrumb">
            @if($networkName)<span>{{ $networkName }}</span>@endif
            @if($networkName && $siteName)<span class="sc-bc-sep">›</span>@endif
            @if($siteName)<span>{{ $siteName }}</span>@endif
        </div>
        @endif
        @endunless

        <div class="sc-title">{{ $screen->name }}</div>
        @if($city)
            <div class="sc-loc">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="sc-loc-icon"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z"/></svg>
                {{ $city }}
            </div>
        @endif

        @unless($compact)
            <div class="sc-specs">
                <div class="sc-spec"><span class="sc-spec-l">Chủ sở hữu</span><span class="sc-spec-v">{{ $screen->owner?->name ?? '—' }}</span></div>
                <div class="sc-spec"><span class="sc-spec-l">Loại hình</span><span class="sc-spec-v">{{ $venueLabel }}</span></div>
                <div class="sc-spec"><span class="sc-spec-l">Kích thước</span><span class="sc-spec-v">{{ $sizeDisplay }}</span></div>
            </div>
        @endunless
    </div>
    <div class="sc-foot">
        @if($price > 0)
        <div>
            <div class="sc-price">{{ $priceDisplay }}<sup>{{ $priceCurrency === 'VND' ? 'đ' : $priceCurrency }}</sup></div>
            <div class="sc-price-sub">/ {{ $priceUnit }}</div>
        </div>
        <span class="btn btn-p btn-sm sc-btn">Chi tiết</span>
        @else
        <div class="sc-price-quote">
            <svg viewBox="0 0 24 24" fill="currentColor" class="sc-quote-ic"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
            Liên hệ báo giá
        </div>
        <span class="btn btn-p btn-sm sc-btn">Chi tiết</span>
        @endif
    </div>
</a>
