<header class="hdr">
    <div class="hdr-in">
        <a href="{{ url('/') }}" class="hdr-logo"><img id="logo" src="" alt="OOHX"></a>
        <nav class="hdr-nav">
            <a href="{{ url('/explore') }}" @class(['ac' => ($activeNav ?? '') === 'explore'])>Khám phá</a>
            <a href="{{ url('/map') }}" @class(['ac' => ($activeNav ?? '') === 'map'])>Bản đồ</a>
            <a href="{{ url('/owners') }}" @class(['ac' => ($activeNav ?? '') === 'owners'])>Chủ sở hữu màn hình</a>
            <a href="{{ route('fp.agency') }}" @class(['ac' => ($activeNav ?? '') === 'agency'])>Đại lý</a>
        </nav>
        <div class="hdr-acts">
            @auth
            {{-- Cart badge --}}
            @php $cartCount = app(\App\Services\CartService::class)->getItemCount(auth()->user()); @endphp
            <a href="{{ route('buyer.cart') }}" class="hdr-ico hdr-cart">
                <svg viewBox="0 0 24 24" fill="var(--t3)" style="width:20px;height:20px"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96C5 16.1 6.9 18 9 18h12v-2H9.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63H19c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 23.43 5H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                @if($cartCount > 0)<div class="hdr-cart-badge" id="cart-badge">{{ $cartCount }}</div>@endif
            </a>
            {{-- User menu --}}
            <div class="usr-menu" id="usr-menu">
                <button class="usr-trigger" id="usr-trigger">
                    <div class="hdr-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                    <svg viewBox="0 0 24 24" fill="var(--t4)" style="width:14px;height:14px;flex-shrink:0"><path d="M7 10l5 5 5-5z"/></svg>
                </button>
                <div class="usr-drop" id="usr-drop">
                    <div class="usr-drop-head">
                        <div class="usr-drop-name">{{ auth()->user()->name }}</div>
                        <div class="usr-drop-email">{{ auth()->user()->email }}</div>
                        @if(auth()->user()->currentOrganization)
                        <div class="usr-drop-org">{{ auth()->user()->currentOrganization->name }}</div>
                        @endif
                    </div>
                    <div class="usr-drop-body">
                        <a href="/my" class="usr-drop-item">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                            Dashboard
                        </a>
                        <a href="/my/campaigns" class="usr-drop-item">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                            Campaigns
                        </a>
                        <a href="/cart" class="usr-drop-item">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96C5 16.1 6.9 18 9 18h12v-2H9.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63H19c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 23.43 5H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                            Plan của tôi
                            @if($cartCount > 0)<span class="usr-drop-badge">{{ $cartCount }}</span>@endif
                        </a>
                        <a href="/my/settings" class="usr-drop-item">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.488.488 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                            Cài đặt
                        </a>
                    </div>
                    <div class="usr-drop-foot">
                        <form method="POST" action="/logout">
                            @csrf
                            <button type="submit" class="usr-drop-item usr-drop-logout">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                                Đăng xuất
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            @else
            {{-- Guest state --}}
            <a href="{{ url('/login') }}" class="btn btn-s btn-sm hdr-login">Đăng nhập</a>
            <a href="{{ url('/register') }}" class="btn btn-p btn-sm hdr-register">Đăng ký</a>
            @endauth
            {{-- Hamburger — visible < 1024px --}}
            <button class="hdr-hamburger" id="hdr-hamburger" aria-label="Menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
        </div>
    </div>
</header>

{{-- Slide menu — full screen overlay for mobile/tablet --}}
<div class="slide-menu" id="slide-menu">
    <div class="slide-menu-head">
        <a href="{{ url('/') }}" class="hdr-logo"><img id="logo-menu" src="" alt="OOHX"></a>
        <button class="slide-menu-close" id="slide-menu-close" aria-label="Đóng">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    </div>
    <nav class="slide-menu-nav">
        <a href="{{ url('/') }}" @class(['on' => ($activeNav ?? '') === 'home'])>
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
            Trang chủ
        </a>
        <a href="{{ url('/explore') }}" @class(['on' => ($activeNav ?? '') === 'explore'])>
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            Khám phá
        </a>
        <a href="{{ url('/map') }}" @class(['on' => ($activeNav ?? '') === 'map'])>
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
            Bản đồ
        </a>
        <a href="{{ url('/owners') }}" @class(['on' => ($activeNav ?? '') === 'owners'])>
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            Chủ sở hữu màn hình
        </a>
        @auth
        <div class="slide-menu-divider"></div>
        <a href="{{ route('buyer.dashboard') }}" @class(['on' => ($activeNav ?? '') === 'dashboard'])>
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
            Dashboard
        </a>
        @endauth
    </nav>
    <div class="slide-menu-foot">
        @auth
        <div class="slide-menu-user">
            <div class="hdr-avatar">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
            <div>
                <div style="font-size:14px;font-weight:700;color:var(--t1)">{{ auth()->user()->name }}</div>
                <div style="font-size:12px;color:var(--t4)">{{ auth()->user()->email }}</div>
            </div>
        </div>
        <form method="POST" action="{{ route('buyer.logout') }}">
            @csrf
            <button type="submit" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">Đăng xuất</button>
        </form>
        @else
        <a href="{{ url('/register') }}" class="btn btn-p" style="width:100%;justify-content:center;border-radius:10px">Đăng ký</a>
        <a href="{{ url('/login') }}" class="btn btn-s" style="width:100%;justify-content:center;border-radius:10px">Đăng nhập</a>
        @endauth
    </div>
</div>
<div class="slide-menu-backdrop" id="slide-menu-backdrop"></div>

<script>
(function(){
    var menu = document.getElementById('slide-menu');
    var backdrop = document.getElementById('slide-menu-backdrop');
    var btn = document.getElementById('hdr-hamburger');
    var close = document.getElementById('slide-menu-close');
    function open(){ menu.classList.add('open'); backdrop.classList.add('open'); document.body.style.overflow='hidden'; }
    function shut(){ menu.classList.remove('open'); backdrop.classList.remove('open'); document.body.style.overflow=''; }
    btn.addEventListener('click', open);
    close.addEventListener('click', shut);
    backdrop.addEventListener('click', shut);

    // User dropdown menu
    var usrTrigger = document.getElementById('usr-trigger');
    var usrDrop = document.getElementById('usr-drop');
    if (usrTrigger && usrDrop) {
        usrTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            usrDrop.classList.toggle('open');
        });
        document.addEventListener('click', function(e) {
            if (!document.getElementById('usr-menu').contains(e.target)) {
                usrDrop.classList.remove('open');
            }
        });
    }
})();
</script>
