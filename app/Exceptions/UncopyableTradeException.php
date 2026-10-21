<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a master contract cannot be reproduced faithfully for a follower. */
class UncopyableTradeException extends RuntimeException {}
