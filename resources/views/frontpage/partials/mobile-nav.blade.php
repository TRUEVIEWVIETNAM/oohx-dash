<nav class="bnav">
    <a href="{{ url('/') }}" @class(['bni', 'on' => ($activeNav ?? '') === 'home'])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="{{ ($activeNav ?? '') === 'home' ? 'var(--bl)' : 'var(--t4)' }}" style="width:24px;height:24px;flex-shrink:0"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        Home
    </a>
    <a href="{{ url('/explore') }}" @class(['bni', 'on' => ($activeNav ?? '') === 'explore'])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="{{ ($activeNav ?? '') === 'explore' ? 'var(--bl)' : 'var(--t4)' }}" style="width:24px;height:24px;flex-shrink:0"><path d="M3 3v8h8V3H3zm6 6H5V5h4v4zm-6 4v8h8v-8H3zm6 6H5v-4h4v4zm4-16v8h8V3h-8zm6 6h-4V5h4v4zm-6 4v8h8v-8h-8zm6 6h-4v-4h4v4z"/></svg>
        Explore
    </a>
    <a href="{{ url('/map') }}" @class(['bni', 'on' => ($activeNav ?? '') === 'map'])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="{{ ($activeNav ?? '') === 'map' ? 'var(--bl)' : 'var(--t4)' }}" style="width:24px;height:24px;flex-shrink:0"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z"/></svg>
        Map
    </a>
    <a href="#" @class(['bni', 'on' => ($activeNav ?? '') === 'saved'])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="{{ ($activeNav ?? '') === 'saved' ? 'var(--bl)' : 'var(--t4)' }}" style="width:24px;height:24px;flex-shrink:0"><path d="M17 3H7c-1.1 0-2 .9-2 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2zm0 15l-5-2.18L7 18V5h10v13z"/></svg>
        Saved
    </a>
    <a href="#" @class(['bni', 'on' => ($activeNav ?? '') === 'account'])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="{{ ($activeNav ?? '') === 'account' ? 'var(--bl)' : 'var(--t4)' }}" style="width:24px;height:24px;flex-shrink:0"><path d="M12 5.9c1.16 0 2.1.94 2.1 2.1s-.94 2.1-2.1 2.1S9.9 9.16 9.9 8s.94-2.1 2.1-2.1m0 9c2.97 0 6.1 1.46 6.1 2.1v1.1H5.9V17c0-.64 3.13-2.1 6.1-2.1M12 4C9.79 4 8 5.79 8 8s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 9c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z"/></svg>
        Account
    </a>
</nav>
