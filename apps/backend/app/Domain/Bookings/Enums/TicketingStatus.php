<?php

namespace App\Domain\Bookings\Enums;

enum TicketingStatus: string
{
    case InProgress = 'in_progress';
    /** Provider call timed out / unknown outcome: verified asynchronously, never assumed. */
    case PendingVerification = 'pending_verification';
    case Issued = 'issued';
    case Failed = 'failed';
    /** Verification attempts exhausted: needs manual follow-up with the provider. */
    case NeedsAttention = 'needs_attention';
}
