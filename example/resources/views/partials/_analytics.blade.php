@if(config('services.google_analytics.id'))
<!-- Google tag (gtag.js) — loaded after cookie consent -->
<script>
  function loadGoogleAnalytics() {
    if (document.getElementById('ga-script')) return;
    const s = document.createElement('script');
    s.id = 'ga-script';
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id={{ config('services.google_analytics.id') }}';
    document.head.appendChild(s);
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', '{{ config('services.google_analytics.id') }}');
@if(session('analytics_event'))
    gtag('event', '{{ session('analytics_event') }}', {!! json_encode(session('analytics_event_params', []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!});
@endif
  }
  if (localStorage.getItem('cookie_consent') === 'accepted') {
    loadGoogleAnalytics();
  }
</script>
@endif
