{{ $appName }}

Hi {{ $user->name }},

Thanks for signing up for {{ $appName }}.

To activate your account, confirm your email address by opening this link:

{{ $verificationUrl }}

This link expires in 60 minutes. If it expires, you can request a new one
from the login page.

---
You're receiving this email because someone (hopefully you) signed up for
{{ $appName }} using this address. If that wasn't you, you can safely
ignore this message.

© {{ date('Y') }} {{ $appName }}. All rights reserved.
