<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/** The provider could not be reached or answered with a server error: the outcome is unknown. */
final class GatewayUnavailable extends RuntimeException {}
