<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fully Automated Bots CT — Copy the Best Trades. Demo Account to Real Account.</title>
    <meta name="description" content="No VPS. No desktop software. Connect your Deriv demo and real accounts and let our web-based platform copy trades automatically, 24/7.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-14px); }
        }
        @keyframes float2 {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-8px) rotate(0.5deg); }
        }
        @keyframes orb1 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.4; }
            33% { transform: translate(60px, -80px) scale(1.3); opacity: 0.6; }
            66% { transform: translate(-40px, 40px) scale(0.8); opacity: 0.25; }
        }
        @keyframes orb2 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.3; }
            33% { transform: translate(-70px, 50px) scale(1.2); opacity: 0.45; }
            66% { transform: translate(50px, -30px) scale(0.9); opacity: 0.2; }
        }
        @keyframes orb3 {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.2; }
            50% { transform: translate(30px, 50px) scale(1.2); opacity: 0.35; }
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes tradeIn {
            from { opacity: 0; transform: translateX(-12px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.5); }
        }
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 20px rgba(30,69,252,0.15); }
            50% { box-shadow: 0 0 40px rgba(30,69,252,0.35); }
        }
        .animate-float  { animation: float 7s ease-in-out infinite; }
        .animate-float2 { animation: float2 9s ease-in-out infinite 1s; }
        .orb-1 { animation: orb1 28s ease-in-out infinite; }
        .orb-2 { animation: orb2 35s ease-in-out infinite 5s; }
        .orb-3 { animation: orb3 22s ease-in-out infinite 10s; }
        .slide-up-1 { animation: slideUp 0.8s ease-out 0.1s both; }
        .slide-up-2 { animation: slideUp 0.8s ease-out 0.25s both; }
        .slide-up-3 { animation: slideUp 0.8s ease-out 0.4s both; }
        .slide-up-4 { animation: slideUp 0.8s ease-out 0.6s both; }
        .slide-up-5 { animation: slideUp 0.8s ease-out 0.75s both; }
        .trade-1 { animation: tradeIn 0.4s ease-out 0.4s both; }
        .trade-2 { animation: tradeIn 0.4s ease-out 0.8s both; }
        .trade-3 { animation: tradeIn 0.4s ease-out 1.2s both; }
        .trade-4 { animation: tradeIn 0.4s ease-out 1.6s both; }
        .trade-5 { animation: tradeIn 0.4s ease-out 2.0s both; }
        .live-pulse { animation: pulseDot 1.8s ease-in-out infinite; }
        .glow-pulse  { animation: glowPulse 3s ease-in-out infinite; }
        .glass-card {
            background: rgba(11, 18, 32, 0.85);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.07);
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-[#020617] text-white antialiased">

{{-- ═══════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════ --}}
<nav
    x-data="{ open: false, scrolled: false }"
    @scroll.window="scrolled = window.scrollY > 20"
    :class="scrolled ? 'bg-[#020617]/90 border-b border-[#1F2937]/60' : 'bg-transparent border-b border-transparent'"
    class="fixed top-0 inset-x-0 z-50 transition-all duration-300 backdrop-blur-md"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            {{-- Logo --}}
            <a href="/" class="flex items-center flex-shrink-0">
                <img src="/logo.svg" alt="Fully Automated Bots CT" class="h-9 w-auto">
            </a>

            {{-- Desktop nav links --}}
            <div class="hidden md:flex items-center gap-7">
                <a href="#about"        class="text-sm text-zinc-400 hover:text-white transition-colors">About</a>
                <a href="#services"     class="text-sm text-zinc-400 hover:text-white transition-colors">Services</a>
                <a href="#how-it-works" class="text-sm text-zinc-400 hover:text-white transition-colors">How It Works</a>
                <a href="#faq"          class="text-sm text-zinc-400 hover:text-white transition-colors">FAQ</a>
            </div>

            {{-- Desktop auth buttons --}}
            <div class="hidden md:flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-lg bg-[#CDF12B] text-[#0B1220] text-sm font-bold hover:bg-[#b8d826] transition-colors">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="px-4 py-2 rounded-lg border border-[#1F2937] text-sm text-zinc-300 hover:text-white hover:border-zinc-500 transition-colors">
                        Login
                    </a>
                    <a href="{{ route('register') }}" class="px-4 py-2 rounded-lg bg-[#CDF12B] text-[#0B1220] text-sm font-bold hover:bg-[#b8d826] transition-colors shadow-lg shadow-[#CDF12B]/20">
                        Start Free
                    </a>
                @endauth
            </div>

            {{-- Mobile hamburger --}}
            <button @click="open = !open" class="md:hidden p-2 rounded-lg text-zinc-400 hover:text-white hover:bg-[#111827] transition-colors">
                <svg x-show="!open" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                <svg x-cloak x-show="open" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Mobile drawer --}}
        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            class="md:hidden border-t border-[#1F2937] py-4 space-y-1"
        >
            <a href="#about"        @click="open=false" class="block px-3 py-2.5 text-sm text-zinc-400 hover:text-white rounded-lg hover:bg-[#111827]/50 transition-colors">About</a>
            <a href="#services"     @click="open=false" class="block px-3 py-2.5 text-sm text-zinc-400 hover:text-white rounded-lg hover:bg-[#111827]/50 transition-colors">Services</a>
            <a href="#how-it-works" @click="open=false" class="block px-3 py-2.5 text-sm text-zinc-400 hover:text-white rounded-lg hover:bg-[#111827]/50 transition-colors">How It Works</a>
            <a href="#faq"          @click="open=false" class="block px-3 py-2.5 text-sm text-zinc-400 hover:text-white rounded-lg hover:bg-[#111827]/50 transition-colors">FAQ</a>
            <div class="flex flex-col gap-2 pt-3 mt-2 border-t border-[#1F2937]">
                @auth
                    <a href="{{ route('dashboard') }}" class="px-4 py-3 rounded-lg bg-[#CDF12B] text-[#0B1220] text-sm font-bold text-center">Dashboard</a>
                @else
                    <a href="{{ route('login') }}"    class="px-4 py-3 rounded-lg border border-[#1F2937] text-sm text-zinc-300 text-center">Login</a>
                    <a href="{{ route('register') }}" class="px-4 py-3 rounded-lg bg-[#CDF12B] text-[#0B1220] text-sm font-bold text-center">Start Free</a>
                @endauth
            </div>
        </div>
    </div>
</nav>

{{-- ═══════════════════════════════════════════
     HERO SECTION
═══════════════════════════════════════════ --}}
<section class="relative min-h-screen flex items-center overflow-hidden bg-[#020617]">

    {{-- Gradient orbs --}}
    <div class="orb-1 absolute top-1/4 left-1/3 w-[500px] h-[500px] rounded-full bg-[#1E45FC]/20 blur-[120px] pointer-events-none"></div>
    <div class="orb-2 absolute bottom-1/3 right-1/4 w-[400px] h-[400px] rounded-full bg-[#1E45FC]/15 blur-[100px] pointer-events-none"></div>
    <div class="orb-3 absolute top-2/3 left-1/2 w-[300px] h-[300px] rounded-full bg-[#1E45FC]/10 blur-[80px] pointer-events-none"></div>

    {{-- Subtle grid --}}
    <div class="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,.015)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.015)_1px,transparent_1px)] bg-[size:64px_64px] pointer-events-none"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-28 w-full">
        <div class="grid lg:grid-cols-2 gap-12 xl:gap-20 items-center">

            {{-- Left: Content --}}
            <div class="text-center lg:text-left">

                {{-- Live badge --}}
                <div class="slide-up-1 inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#CDF12B]/10 border border-[#CDF12B]/25 text-[#CDF12B] text-xs font-semibold mb-7">
                    <span class="live-pulse w-1.5 h-1.5 rounded-full bg-[#CDF12B] inline-block"></span>
                    Live on Deriv API
                </div>

                {{-- Headline --}}
                <h1 class="slide-up-2 text-4xl sm:text-5xl lg:text-6xl xl:text-[4.5rem] font-extrabold tracking-tight leading-[1.1] mb-6">
                    Copy the Best<br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#1E45FC] via-[#6B8AFF] to-[#8FAEFF]">Trades.</span><br>
                    Demo to Real.
                </h1>

                {{-- Subheadline --}}
                <p class="slide-up-3 text-lg sm:text-xl text-zinc-400 leading-relaxed max-w-lg mx-auto lg:mx-0 mb-9">
                    Say goodbye to complex desktop installations and VPS setups. Our newly developed web-based system gives you full control of your copy trading from any device. Designed to adapt seamlessly to the latest Deriv API environments, it ensures your connection remains stable, secure, and uninterrupted 24/7.
                </p>

                {{-- CTA buttons --}}
                <div class="slide-up-4 flex flex-col sm:flex-row gap-3 justify-center lg:justify-start mb-10">
                    @guest
                        <a href="{{ route('register') }}" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-[#CDF12B] text-[#0B1220] font-bold text-base hover:bg-[#b8d826] transition-all hover:scale-[1.03] active:scale-95 shadow-lg shadow-[#CDF12B]/30">
                            Start for Free
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>
                    @else
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-[#CDF12B] text-[#0B1220] font-bold text-base hover:bg-[#b8d826] transition-all hover:scale-[1.03] shadow-lg shadow-[#CDF12B]/30">
                            Go to Dashboard
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                        </a>
                    @endauth
                    <a href="#how-it-works" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl border border-[#1F2937] text-white font-semibold text-base hover:bg-[#111827]/60 transition-all">
                        See How It Works
                        <svg class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </a>
                </div>

                {{-- Trust badges --}}
                <div class="slide-up-5 flex flex-wrap items-center justify-center lg:justify-start gap-x-5 gap-y-2 text-xs text-zinc-500">
                    <span>🔒 Deriv API Secured</span>
                    <span class="hidden sm:block w-px h-3 bg-[#1F2937]"></span>
                    <span>⚡ Real-Time Execution</span>
                    <span class="hidden sm:block w-px h-3 bg-[#1F2937]"></span>
                    <span>📊 Live P&L Tracking</span>
                    <span class="hidden sm:block w-px h-3 bg-[#1F2937]"></span>
                    <span>🖥️ No VPS or Desktop App</span>
                </div>
            </div>

            {{-- Right: Floating mockup cards --}}
            <div class="hidden lg:flex items-center justify-center relative h-[480px]">

                {{-- Balance card --}}
                <div class="animate-float absolute top-4 right-4 w-72 glass-card rounded-2xl p-5 shadow-2xl glow-pulse z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-[11px] text-zinc-500 mb-1 uppercase tracking-wide">Total Balance</p>
                            <p class="text-2xl font-extrabold text-white tabular-nums">$1,247.50</p>
                        </div>
                        <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[#1E45FC]/15 border border-[#1E45FC]/25">
                            <span class="live-pulse w-1.5 h-1.5 rounded-full bg-[#1E45FC] inline-block"></span>
                            <span class="text-[#1E45FC] text-[11px] font-semibold">Live</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-[#22C55E] font-semibold text-sm">+$47.20 today</span>
                        <span class="text-zinc-700">·</span>
                        <span class="text-zinc-500 text-xs">3 accounts live</span>
                    </div>
                    <div class="h-1 bg-[#1F2937] rounded-full overflow-hidden">
                        <div class="h-full w-[73%] bg-gradient-to-r from-[#1E45FC] to-[#6B8AFF] rounded-full"></div>
                    </div>
                </div>

                {{-- Trade feed card --}}
                <div class="animate-float2 absolute bottom-4 left-0 w-[300px] glass-card rounded-2xl p-5 shadow-2xl z-10">
                    <div class="flex items-center justify-between mb-4">
                        <p class="text-xs font-bold text-white tracking-wide">Live Trade Feed</p>
                        <div class="flex items-center gap-1.5 text-[11px] text-zinc-500">
                            <span class="live-pulse w-1.5 h-1.5 rounded-full bg-[#22C55E] inline-block"></span>
                            Real-time
                        </div>
                    </div>
                    <div class="space-y-2.5">
                        <div class="trade-1 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#22C55E]/20 text-[#22C55E]">CALL</span>
                                <span class="text-xs text-zinc-300 font-mono">R_100</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 tabular-nums">$2.50</span>
                                <span class="text-[11px] font-semibold text-[#22C55E]">✓ Won</span>
                            </div>
                        </div>
                        <div class="trade-2 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#FF5A5F]/20 text-[#FF5A5F]">PUT</span>
                                <span class="text-xs text-zinc-300 font-mono">EURUSD</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 tabular-nums">$1.00</span>
                                <span class="text-[11px] font-semibold text-[#FF5A5F]">✗ Lost</span>
                            </div>
                        </div>
                        <div class="trade-3 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#22C55E]/20 text-[#22C55E]">CALL</span>
                                <span class="text-xs text-zinc-300 font-mono">R_50</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 tabular-nums">$5.00</span>
                                <span class="text-[11px] font-semibold text-[#22C55E]">✓ Won</span>
                            </div>
                        </div>
                        <div class="trade-4 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#FF5A5F]/20 text-[#FF5A5F]">PUT</span>
                                <span class="text-xs text-zinc-300 font-mono">R_100</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 tabular-nums">$2.00</span>
                                <span class="text-[11px] font-semibold text-[#22C55E]">✓ Won</span>
                            </div>
                        </div>
                        <div class="trade-5 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-[#22C55E]/20 text-[#22C55E]">CALL</span>
                                <span class="text-xs text-zinc-300 font-mono">GBPUSD</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-zinc-500 tabular-nums">$3.00</span>
                                <span class="text-[11px] font-semibold text-yellow-400 animate-pulse">● Pending</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Background ambient glow --}}
                <div class="absolute inset-8 bg-[#1E45FC]/5 rounded-3xl blur-2xl pointer-events-none"></div>
            </div>
        </div>
    </div>

    {{-- Bottom fade --}}
    <div class="absolute bottom-0 inset-x-0 h-28 bg-gradient-to-t from-[#020617] to-transparent pointer-events-none"></div>
</section>

{{-- ═══════════════════════════════════════════
     STATS BAR
═══════════════════════════════════════════ --}}
<section class="border-y border-[#1F2937]/50 bg-[#0B1220]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-10">
            @foreach ([
                ['100%',  'Web-Based — No VPS Needed'],
                ['< 3s',  'Avg Copy Speed'],
                ['98%',   'Platform Uptime'],
                ['24/7',  'Uninterrupted Connection'],
            ] as [$num, $label])
            <div class="text-center">
                <p class="text-3xl lg:text-4xl font-extrabold text-[#1E45FC] mb-1.5 tabular-nums">{{ $num }}</p>
                <p class="text-sm text-zinc-500">{{ $label }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════
     ABOUT SECTION
═══════════════════════════════════════════ --}}
<section id="about" class="py-24 bg-[#020617]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <p class="text-[#1E45FC] text-xs font-bold uppercase tracking-widest mb-3">About</p>
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">What is Fully Automated Bots CT?</h2>
        </div>
        <div class="grid lg:grid-cols-2 gap-12 xl:gap-20 items-start">
            <div class="space-y-5 text-zinc-400 text-base leading-relaxed">
                <p>
                    Fully Automated Bots CT is a web-based copy trading platform built directly on the
                    <span class="text-white font-semibold">Deriv API</span>. Link your Deriv demo and real accounts, configure your settings, and the platform automatically copies trades from your demo account to your real account — no VPS, no desktop software, no complicated setup.
                </p>
                <p>
                    Use your <span class="text-white font-semibold">demo account as the source</span> to test strategies risk-free, while your real account follows and executes the same trades live. Perfect for validating signals before committing real capital.
                </p>
                <p>
                    Connect using <span class="text-white font-semibold">Deriv OAuth</span> with one click, or paste a <span class="text-white font-semibold">Personal Access Token</span> directly — whichever you prefer. Your token is stored encrypted at rest and is never readable in plain text.
                </p>
                <p>
                    Your funds <span class="text-white font-semibold">never leave your Deriv account</span>. View live account summaries, real-time trade activity, and full P&L breakdowns from a single dashboard — your data fetched directly from Deriv, always up to date.
                </p>
            </div>
            <div class="space-y-3">
                @foreach ([
                    'Self-copy trading — Demo account drives your Real account',
                    'Connect via Deriv OAuth or Personal Access Token',
                    'Encrypted token storage — AES-256, never readable in plain text',
                    'Your funds stay in YOUR Deriv account at all times',
                    'Slave pattern filter — define the win/loss sequence before copying activates',
                    'Live account summaries: balance, P&L, and real-time trade feed',
                    'Take profit and stop loss — bot pauses automatically when limits are hit',
                    'Pause or stop copying at any time, instantly from your dashboard',
                ] as $feature)
                <div class="flex items-center gap-3 p-4 rounded-xl bg-[#0B1220]/60 border border-[#1F2937] hover:border-[#1E45FC]/30 transition-colors">
                    <div class="flex-shrink-0 w-6 h-6 rounded-full bg-[#1E45FC]/15 border border-[#1E45FC]/30 flex items-center justify-center">
                        <svg class="w-3 h-3 text-[#1E45FC]" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <span class="text-sm text-zinc-300">{{ $feature }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════
     SERVICES SECTION
═══════════════════════════════════════════ --}}
<section id="services" class="py-24 bg-[#111827]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <p class="text-[#1E45FC] text-xs font-bold uppercase tracking-widest mb-3">Services</p>
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">Everything You Need to Copy Smarter</h2>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ([
                [
                    'title'    => 'Demo-to-Real Copy Trading',
                    'desc'     => 'Your Deriv demo account acts as the source — every trade it places is mirrored to your real account in real time via the official Deriv WebSocket API. Test strategies risk-free, then run them live automatically.',
                    'icon'     => 'M13 10V3L4 14h7v7l9-11h-7z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Slave Pattern Filter',
                    'desc'     => 'Define a custom win/loss sequence — e.g. "111" for three consecutive wins — that must match before copying activates on your real account. Keeps you out of cold streaks automatically.',
                    'icon'     => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Live P&L Dashboard',
                    'desc'     => 'Track every trade with real-time win rate, total P&L, best and worst trade, and average stake — pulled live from your Deriv account statement the moment each trade settles.',
                    'icon'     => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Demo & Real Account Management',
                    'desc'     => 'View both your demo and real Deriv accounts side-by-side with live balances and a real-time transaction feed. Switch accounts, reset demo balance, and monitor all activity from one place.',
                    'icon'     => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Flexible Connection — OAuth & PAT',
                    'desc'     => 'Authorize with one click via Deriv OAuth, or connect directly using a Personal Access Token. Your credentials are stored with AES-256 encryption and never exposed in plain text.',
                    'icon'     => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Stake, Take Profit & Stop Loss',
                    'desc'     => 'Set a fixed stake amount, configure take profit and stop loss limits per session, and let the bot pause automatically when thresholds are hit. All settings can be updated at any time.',
                    'icon'     => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                    'soon'     => false,
                ],
                [
                    'title'    => 'Copy From Platform Masters',
                    'desc'     => 'Follow verified master traders on the platform and let our system mirror their trades to your account automatically — with full control over stake, risk, and pattern settings.',
                    'icon'     => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
                    'soon'     => true,
                ],
            ] as $card)
            <div class="group relative p-6 rounded-2xl bg-[#0B1220] border {{ $card['soon'] ? 'border-[#1F2937]/50 opacity-70' : 'border-[#1F2937] hover:border-[#1E45FC]/30 hover:shadow-[0_0_40px_rgba(30,69,252,0.07)] hover:scale-[1.02]' }} transition-all duration-300 cursor-default">
                @if($card['soon'])
                    <div class="absolute top-4 right-4 px-2 py-0.5 rounded-full bg-zinc-700/60 border border-zinc-600/40 text-[10px] font-bold uppercase tracking-wide text-zinc-400">Coming Soon</div>
                @endif
                <div class="w-11 h-11 rounded-xl {{ $card['soon'] ? 'bg-zinc-800/60' : 'bg-[#1E45FC]/10 group-hover:bg-[#1E45FC]/15' }} flex items-center justify-center mb-5 transition-colors">
                    <svg class="w-5 h-5 {{ $card['soon'] ? 'text-zinc-500' : 'text-[#1E45FC]' }}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                        <path d="{{ $card['icon'] }}"/>
                    </svg>
                </div>
                <h3 class="text-sm font-bold {{ $card['soon'] ? 'text-zinc-400' : 'text-white' }} mb-2">{{ $card['title'] }}</h3>
                <p class="text-sm text-zinc-500 leading-relaxed">{{ $card['desc'] }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════
     HOW IT WORKS SECTION
═══════════════════════════════════════════ --}}
<section id="how-it-works" class="py-24 bg-[#020617]">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-5">
            <p class="text-[#1E45FC] text-xs font-bold uppercase tracking-widest mb-3">Process</p>
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight">Up and Running in Minutes</h2>
        </div>
        <p class="text-center text-zinc-500 text-lg mb-16">No complicated setup. No coding. Just connect and copy.</p>

        {{-- Steps: vertical timeline --}}
        <div class="relative space-y-4">
            {{-- Vertical connector --}}
            <div class="absolute left-6 top-14 bottom-14 w-px bg-gradient-to-b from-[#CDF12B]/40 via-[#1F2937] to-transparent pointer-events-none hidden sm:block"></div>

            {{-- Step 01 --}}
            <div class="relative flex gap-6 items-start p-6 rounded-2xl bg-[#0B1220] border border-[#1F2937]">
                <div class="w-12 h-12 rounded-full bg-[#CDF12B] flex items-center justify-center text-[#0B1220] font-extrabold text-sm shadow-lg shadow-[#CDF12B]/30 flex-shrink-0 relative z-10">01</div>
                <div>
                    <h3 class="text-sm font-bold text-white mb-1">Create Your Account</h3>
                    <p class="text-sm text-zinc-500 leading-relaxed">
                        Sign up free and verify your email — under 60 seconds, no credit card required.
                        No Deriv account yet? <a href="https://track.deriv.com/_Ed6zZUkRQYaX6ytsi48cKWNd7ZgqdRLk/1/" target="_blank" rel="noopener" class="text-[#CDF12B] hover:underline font-semibold">Create one free here</a>.
                    </p>
                </div>
            </div>

            {{-- Step 02 --}}
            <div class="relative flex gap-6 items-start p-6 rounded-2xl bg-[#0B1220] border border-[#1F2937]">
                <div class="w-12 h-12 rounded-full bg-[#CDF12B] flex items-center justify-center text-[#0B1220] font-extrabold text-sm shadow-lg shadow-[#CDF12B]/30 flex-shrink-0 relative z-10">02</div>
                <div>
                    <h3 class="text-sm font-bold text-white mb-1">Connect Your Deriv Account</h3>
                    <p class="text-sm text-zinc-500 leading-relaxed">
                        Use one-click Deriv OAuth or paste a Personal Access Token. Credentials are encrypted at rest — never stored in plain text.
                    </p>
                </div>
            </div>

            {{-- Step 03 --}}
            <div class="relative flex gap-6 items-start p-6 rounded-2xl bg-[#0B1220] border border-[#1F2937]">
                <div class="w-12 h-12 rounded-full bg-[#CDF12B] flex items-center justify-center text-[#0B1220] font-extrabold text-sm shadow-lg shadow-[#CDF12B]/30 flex-shrink-0 relative z-10">03</div>
                <div class="flex-1">
                    <h3 class="text-sm font-bold text-white mb-2">Configure Accounts & Settings</h3>
                    <p class="text-sm text-zinc-500 leading-relaxed mb-3">
                        Master Account = Demo · Follower Account = Real. Set your Slave Pattern, Stake, Take Profit, and Stop Loss — all adjustable at any time.
                    </p>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[#111827] border border-[#1F2937]">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#CDF12B] flex-shrink-0"></span>
                            <span class="text-xs text-zinc-400"><span class="text-zinc-200 font-medium">Testing:</span> select Master Account twice (Demo)</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[#111827] border border-[#1F2937]">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#1E45FC] flex-shrink-0"></span>
                            <span class="text-xs text-zinc-400"><span class="text-zinc-200 font-medium">Live trading:</span> select Master + Follower Account</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 04 --}}
            <div class="relative flex gap-6 items-start p-6 rounded-2xl bg-[#0B1220] border border-[#1E45FC]/30">
                <div class="w-12 h-12 rounded-full bg-[#CDF12B] flex items-center justify-center text-[#0B1220] font-extrabold text-sm shadow-lg shadow-[#CDF12B]/30 flex-shrink-0 relative z-10">04</div>
                <div>
                    <h3 class="text-sm font-bold text-white mb-1">Trades Copy Automatically</h3>
                    <p class="text-sm text-zinc-500 leading-relaxed">
                        Trades copy in real time following your Slave Pattern. Your balance and P&L update instantly on the dashboard.
                    </p>
                </div>
            </div>

        </div>

        {{-- CTA card --}}
        <div class="mt-20 relative overflow-hidden rounded-2xl border border-[#1E45FC]/20 bg-gradient-to-br from-[#1E45FC]/20 via-[#1E45FC]/5 to-[#0B1220]/50 p-8 sm:p-12 text-center">
            <div class="absolute inset-0 bg-[linear-gradient(rgba(30,69,252,.025)_1px,transparent_1px),linear-gradient(90deg,rgba(30,69,252,.025)_1px,transparent_1px)] bg-[size:40px_40px] pointer-events-none"></div>
            <div class="relative">
                <h3 class="text-2xl sm:text-3xl font-extrabold text-white mb-3">Ready to start?</h3>
                <p class="text-zinc-400 text-base max-w-md mx-auto mb-8">
                    Start copying your demo trades to your real account today. Free to get started — no credit card required.
                </p>
                @guest
                    <a href="{{ route('register') }}" class="inline-flex items-center gap-2 px-8 py-4 rounded-xl bg-[#CDF12B] text-[#0B1220] font-bold text-base hover:bg-[#b8d826] transition-all hover:scale-[1.03] shadow-lg shadow-[#CDF12B]/30">
                        Create Free Account
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                @else
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 px-8 py-4 rounded-xl bg-[#CDF12B] text-[#0B1220] font-bold text-base hover:bg-[#b8d826] transition-all hover:scale-[1.03] shadow-lg shadow-[#CDF12B]/30">
                        Go to Dashboard
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                @endauth
            </div>
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════
     FAQ SECTION
═══════════════════════════════════════════ --}}
<section id="faq" class="py-24 bg-[#111827]">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <p class="text-[#1E45FC] text-xs font-bold uppercase tracking-widest mb-3">FAQ</p>
            <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight">Frequently Asked Questions</h2>
        </div>
        <div class="space-y-2">
            @foreach ([
                [
                    'q' => 'Is my Deriv account safe?',
                    'a' => "Yes. Your Deriv credentials are stored using AES-256-CBC encryption — so even if our database were compromised, your token would be completely unreadable. Trades are always executed inside YOUR Deriv account via your own token. We never hold your funds, never move money between accounts, and never have access beyond what the token permits.",
                ],
                [
                    'q' => 'What is the difference between the Master Account and Follower Account?',
                    'a' => "In this platform, Master Account = Demo Account and Follower Account = Real Account. Your demo account acts as the signal source — every trade it places is mirrored to your real account automatically. For testing, you can set both Master and Follower to the same demo account. For live trading, set the Master to your demo account and the Follower to your real account.",
                ],
                [
                    'q' => 'What is the Slave Pattern and how do I set it?',
                    'a' => "The Slave Pattern is a win/loss filter — e.g. '111' means three consecutive wins must occur on the master (demo) account before copying activates on the follower (real) account. This keeps your real account out of cold streaks. You set it during setup and can change it at any time from your dashboard.",
                ],
                [
                    'q' => 'How does the real-time copying work?',
                    'a' => "Our server maintains a persistent Deriv WebSocket connection for your master (demo) account. The moment a trade is detected, a copy job places the same trade on your follower (real) account within seconds — completely automatically. A heartbeat system monitors the connection and restarts it if it ever drops.",
                ],
                [
                    'q' => 'How fast are trades copied?',
                    'a' => "Typically under 3 seconds. Our persistent WebSocket connection receives trade events from Deriv the moment they happen — there is no polling delay. Final speed depends on network latency and Deriv's own execution time.",
                ],
                [
                    'q' => 'How much does it cost?',
                    'a' => "Free to get started. We earn a commission through Deriv's official markup system: a small percentage is built into the price of each contract placed through our platform, so the payout on a winning trade is slightly lower than trading on Deriv directly. There are no subscriptions or other charges.",
                ],
                [
                    'q' => 'Can I set limits to protect my capital?',
                    'a' => "Yes. Each copy session supports a take profit target and a stop loss threshold — the bot pauses automatically when either is reached. You can update these limits at any time from your dashboard without restarting the session.",
                ],
                [
                    'q' => 'Can I pause or stop copying at any time?',
                    'a' => "Yes. You can manually pause or stop copying at any time directly from your dashboard — no delay, no waiting. Copying stops immediately. Your Deriv account and any open trades are completely unaffected.",
                ],
                [
                    'q' => 'Can I follow master traders from other users on the platform?',
                    'a' => "This feature is coming soon. Currently the platform focuses on copying trades from your own demo account to your real account. Following platform masters will be available in a future release.",
                ],
                [
                    'q' => 'What is Deriv?',
                    'a' => "Deriv (formerly Binary.com) is a regulated online trading platform offering binary options, multipliers, and synthetic indices available 24/7. Fully Automated Bots CT is built directly on their official WebSocket API — no screen scraping, no unofficial methods, just a clean API integration.",
                ],
            ] as $faq)
            <div x-data="{ open: false }" class="rounded-xl border border-[#1F2937] bg-[#0B1220]/40 overflow-hidden">
                <button
                    @click="open = !open"
                    class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-[#111827]/30 transition-colors gap-4"
                >
                    <span class="text-sm font-semibold text-white">{{ $faq['q'] }}</span>
                    <svg
                        :class="open ? 'rotate-180' : ''"
                        class="w-4 h-4 text-zinc-500 flex-shrink-0 transition-transform duration-200"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div
                    x-cloak
                    x-show="open"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="px-5 pb-5"
                >
                    <p class="text-sm text-zinc-400 leading-relaxed border-t border-[#1F2937] pt-4">{{ $faq['a'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════ --}}
<footer class="bg-[#0B1220] border-t-2 border-[#1E45FC]/30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid md:grid-cols-3 gap-10 lg:gap-16">

            {{-- Col 1: Brand --}}
            <div>
                <div class="mb-4">
                    <img src="/logo.svg" alt="Fully Automated Bots CT" class="h-9 w-auto">
                </div>
                <p class="text-sm text-zinc-500 leading-relaxed mb-5">
                    The web-based copy trading platform for Deriv traders. Demo to Real — automated, secure, 24/7.
                </p>
                <span class="inline-flex items-center gap-1.5 text-xs text-zinc-600 bg-[#0B1220] border border-[#1F2937] rounded-lg px-3 py-1.5">
                    <svg class="w-3 h-3 text-[#1E45FC]" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    Built on Deriv API
                </span>
            </div>

            {{-- Col 2: Quick links --}}
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-zinc-400 mb-5">Quick Links</h4>
                <div class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                    @foreach ([
                        ['Home',         '/'],
                        ['About',        '#about'],
                        ['Services',     '#services'],
                        ['How It Works', '#how-it-works'],
                        ['FAQ',          '#faq'],
                        ['Login',        route('login')],
                        ['Register',     route('register')],
                    ] as [$label, $href])
                    <a href="{{ $href }}" class="text-sm text-zinc-500 hover:text-white transition-colors py-0.5">{{ $label }}</a>
                    @endforeach
                </div>
            </div>

            {{-- Col 3: Risk warning --}}
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-zinc-400 mb-5">Risk Warning</h4>
                <p class="text-xs text-zinc-600 leading-relaxed">
                    Trading in financial instruments involves substantial risk of loss and is not suitable for all investors.
                    Past performance of master traders is not indicative of future results.
                </p>
                <p class="text-xs text-zinc-600 leading-relaxed mt-3">
                    Fully Automated Bots CT is a technology service only and does not provide investment advice.
                    You are solely responsible for all trading decisions made through our platform.
                </p>
                <p class="text-xs text-zinc-700 leading-relaxed mt-3">
                    This service is not affiliated with, endorsed by, or in any way officially connected with Deriv.com or its parent companies.
                </p>
            </div>
        </div>

        {{-- Bottom bar --}}
        <div class="mt-12 pt-6 border-t border-[#1F2937]/60 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="text-xs text-zinc-600">© {{ date('Y') }} Fully Automated Bots CT. All rights reserved.</p>
            <p class="text-xs text-zinc-700">Not affiliated with Deriv.com</p>
        </div>
    </div>
</footer>

@include('partials.support-fabs')
</body>
</html>
