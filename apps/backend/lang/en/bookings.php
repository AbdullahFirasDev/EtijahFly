<?php

return [
    'events' => [
        'created' => 'Booking created for :route.',
        'awaiting_ticketing' => 'Provider does not support holds; the booking will be purchased at issuance.',
        'hold_created' => 'Hold placed with the provider (PNR :pnr) until :expires.',
        'hold_failed' => 'The provider rejected the booking: :reason',
        'unexpected_provider_status' => 'The provider returned an unexpected status (:status) instead of a hold.',
        'provider_timeout' => 'The provider did not answer in time. Verifying the booking status with the provider.',
        'reconciliation_failed' => 'The booking was not found at the provider after several checks.',
        'charged' => ':amount charged to the company account.',
        'ticketed' => 'The provider issued :count e-ticket(s).',
        'ticketing_failed' => 'Ticketing failed at the provider and the charge was reversed: :reason',
        'ticketing_pending_verification' => 'Ticketing outcome not confirmed by the provider; verifying. Tickets are not considered issued until confirmed.',
        'ticketing_needs_attention' => 'Ticketing outcome could not be verified automatically; manual follow-up with the provider is required.',
        'ticketing_not_completed' => 'The provider confirmed tickets were not issued (status :status).',
        'provider_no_record' => 'No record of the booking at the provider.',
        'cancelled' => 'Booking cancelled: :reason',
        'cancel_failed' => 'Cancellation failed at the provider: :reason',
        'hold_expired' => 'The hold expired.',
        'hold_extended' => 'The provider extended the hold.',
        'refund_requested' => 'Refund requested: :reason',
        'refund_pending' => 'Refund pending at the provider.',
        'refunded' => 'Refund completed; :amount returned to the company account.',
        'refund_rejected' => 'Refund rejected: :reason',
    ],
];
