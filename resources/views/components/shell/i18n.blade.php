{{-- Current language and the texts browser scripts show (resources/js/modules/i18n.js). --}}
<script>
    window.AppI18n = @json(['locale' => app()->getLocale(), 'messages' => (object) trans_js()]);
</script>
