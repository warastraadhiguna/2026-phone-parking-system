<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/** The payment configuration is not allowed in this environment (e.g. fake gateway in production). */
final class UnsafePaymentConfiguration extends RuntimeException {}
