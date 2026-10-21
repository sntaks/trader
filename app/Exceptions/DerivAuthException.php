<?php

namespace App\Exceptions;

/** Deriv rejected the token (HTTP 401): it expired or was revoked, and only reconnecting fixes it. */
class DerivAuthException extends DerivApiException {}
