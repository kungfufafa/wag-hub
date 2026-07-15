<?php

namespace App\Domain\Delivery;

enum DeliveryCertainty: string
{
    case NotSent = 'not_sent';
    case Accepted = 'accepted';
    case Unknown = 'unknown';
}
