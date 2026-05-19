{{ config('app.name') }}

Hi {{ $user->name }},

Welcome to {{ config('app.name') }} — your account is set up and ready to go.

Here's what you can do next:
  * Connect your Deriv account to start copy trading
  * Follow top-performing masters automatically
  * Monitor every copied trade in real time

Open your dashboard:
{{ route('dashboard') }}

---
You're receiving this email because you signed up for {{ config('app.name') }}.

© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
