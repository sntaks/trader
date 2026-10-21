<?php

namespace App\Exceptions;

/** Deriv's REST budget is spent, or Deriv asked us to back off; nothing was sent. */
class DerivRateLimitedException extends DerivApiException {}
