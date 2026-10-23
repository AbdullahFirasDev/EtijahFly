<?php

namespace App\Domain\Bookings\Enums;

enum RefundStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
