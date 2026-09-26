<?php

namespace App\Support\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * One JSON object per line. Redaction happens here, at the last step before output,
 * so it also covers data added by processors (e.g. Laravel Context in "extra").
 */
class RedactingJsonFormatter extends JsonFormatter
{
    private SensitiveDataRedactor $redactor;

    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, false, true);
        $this->redactor = new SensitiveDataRedactor;
    }

    public function format(LogRecord $record): string
    {
        return parent::format($record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($record->extra),
        ));
    }
}
