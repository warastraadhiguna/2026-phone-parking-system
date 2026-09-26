<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/** A notification that is not authentic (bad signature) or cannot be understood. Never processed. */
final class InvalidWebhook extends RuntimeException {}
