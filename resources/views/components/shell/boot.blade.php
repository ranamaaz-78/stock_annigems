{{-- Apply stored sidebar density and kiosk fullscreen before first paint. --}}
<script>
    (function () {
        try {
            if (localStorage.getItem('annigems.sidebar') === 'mini') {
                document.documentElement.classList.add('is-sidebar-mini');
            }
            if (localStorage.getItem('annigems.fullscreen') === '1') {
                document.documentElement.classList.add('is-app-fullscreen');
            }
        } catch (e) {}
    })();
</script>
