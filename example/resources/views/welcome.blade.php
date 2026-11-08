@extends('layouts.app')
@section('content')
<div class="welcome">
    <section class="hero">
        <img src="/images/logo-icon.svg" alt="" width="100" height="100">
        <h1>{{ config('app.name') }}</h1>
        <p class="lead">The all-in-one industry management toolkit for EVE Online. Track jobs across multiple characters, calculate profits, and optimise your industrial empire.</p>
        <div class="actions">
            <a class="btn-eve" href="{{ route('auth.eve.scopes') }}">Login with EVE Online</a>
            @if(config('demo.enabled'))
            <form method="POST" action="{{ route('demo.login') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn-discord">Try the demo</button>
            </form>
            @endif
            @if(config('app.discord_url'))
            <a class="btn-discord" href="{{ config('app.discord_url') }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>Join us on Discord</a>
            @endif
        </div>
    </section>

    <div class="welcome-layout">
        <div>
            <article class="card highlight">
                <span class="badge-new">NEW</span>
                <div class="highlight-text">
                    <h2>Advanced Reaction Calculator</h2>
                    <p class="lead">Plan complex multi-reaction cycles with our powerful new reaction planner.</p>
                    <ul class="checks">
                        <li>Calculate raw material requirements for entire reaction chains</li>
                        <li>Plan multiple composite and intermediate reactions</li>
                        <li>Track slots required across cycle lengths</li>
                        <li>Save and manage reaction plans</li>
                        <li>Export to EVE muti-buy</li>
                    </ul>
                </div>
                <img src="/images/reaction-calculator-screenshot.png" alt="Reaction Calculator Screenshot" loading="lazy">
            </article>

            <h2 class="visually-hidden">Features</h2>
            <div class="feature-grid">
                @foreach([
                    'Industry Job Tracking' => 'Monitor manufacturing, research, and invention jobs across all your characters in one unified dashboard.',
                    'Multi-Character Support' => 'Link multiple EVE characters to a single account. Manage your entire industrial fleet from one place.',
                    'Profit Calculators' => 'Manufacturing & reaction calculators with real-time market data to find the most profitable products.',
                    'Slot Availability' => 'See available manufacturing and research slots at a glance. Never miss an open production window.',
                    'Reprocessing Analysis' => 'Evaluate reprocessing value of items to maximise returns on loot and salvage.',
                    'Planetary Interaction' => 'Track your planetary colonies, extraction cycles, and production chains across all characters.',
                    'Asset Management' => 'View and search your complete inventory across all characters and locations, with sorting and filtering.',
                    'Blueprint Research' => 'Track blueprint and reaction formula research levels across all characters with CSV export.',
                    'Build Programs' => 'Create and track large manufacturing projects. Consolidate multiple jobs into a single tracked program.',
                    'Invention Planner' => 'Plan invention jobs with probability calculations, material requirements, and profit analysis.',
                    'Contract Tools' => 'Appraise contract items for instant ISK valuations and browse public contracts with search and filtering.',
                    'Moon Scan Analyser' => 'Analyse moon scan data to find valuable ore compositions, track occupation status, and estimate extraction values.',
                ] as $title => $description)
                <article class="card feature">
                    <h3>{{ $title }}</h3>
                    <p>{{ $description }}</p>
                </article>
                @endforeach
            </div>
        </div>

        @if($sponsors->isNotEmpty())
        <aside class="card sponsors" aria-labelledby="sponsors-heading">
            <h2 id="sponsors-heading">Sponsors</h2>
            @foreach($sponsors as $sponsor)
            <a class="sponsor-banner" href="{{ $sponsor->url }}" target="_blank" rel="noopener sponsored" title="{{ $sponsor->name }}">
                <img src="{{ $sponsor->imageUrl() }}" alt="{{ $sponsor->name }}" loading="lazy">
            </a>
            @endforeach
        </aside>
        @endif
    </div>

    <section class="hero cta">
        <h2>Ready to optimise your industry?</h2>
        <a class="btn-eve" href="{{ route('auth.eve.scopes') }}">Get Started with EVE SSO</a>
    </section>
    <p class="legal-footer">
        <a href="{{ config('app.source_url') }}" rel="noopener">Source code (AGPL-3.0)</a> ·
        Unofficial fan tool, provided as is. EVE Online and related marks are &copy; CCP hf.
    </p>
</div>
@endsection
