<?php

namespace App\Domain\Payment\Enums;

enum ProviderEventSource: string
{
    case CHARGE = 'CHARGE';
    case STATUS_CHECK = 'STATUS_CHECK';
    case CANCEL = 'CANCEL';
    case WEBHOOK = 'WEBHOOK';
}
