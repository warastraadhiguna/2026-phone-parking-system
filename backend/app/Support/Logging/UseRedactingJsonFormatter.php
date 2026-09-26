<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;

/**
 * Logging "tap" applied to the structured channel (config/logging.php).
 */
class UseRedactingJsonFormatter
{
    public function __invoke(Logger $logger): void
    {
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new RedactingJsonFormatter);
            }
        }
    }
}
