<?php

return [
    'open' => 'Open',
    'booking_held' => ['title' => 'Booking :reference on hold', 'body' => 'Booking :reference (:route) is on hold until :expires. Issue tickets before the hold expires.'],
    'hold_expiring' => ['title' => 'Hold :reference expiring soon', 'body' => 'The hold for :reference (:route) expires at :expires.'],
    'booking_expired' => ['title' => 'Hold :reference expired', 'body' => 'The hold for :reference (:route) expired without ticketing.'],
    'booking_ticketed' => ['title' => 'Tickets issued for :reference', 'body' => 'The provider issued e-tickets for booking :reference (:route).'],
    'ticketing_failed' => ['title' => 'Ticketing failed for :reference', 'body' => 'Tickets for :reference could not be issued and the amount was returned to your account. Reason: :reason'],
    'booking_failed' => ['title' => 'Booking :reference could not be created', 'body' => 'The provider rejected booking :reference. Reason: :reason'],
    'booking_cancelled' => ['title' => 'Booking :reference cancelled', 'body' => 'Booking :reference (:route) was cancelled.'],
    'booking_refunded' => ['title' => 'Refund completed for :reference', 'body' => 'The refund for :reference is complete and was credited to your account.'],
    'payment_approved' => ['title' => 'Payment :reference approved', 'body' => ':amount was added to your company balance.'],
    'payment_rejected' => ['title' => 'Payment :reference rejected', 'body' => 'Payment :reference of :amount was not approved. See the review notes.'],
    'low_balance' => ['title' => 'Low balance', 'body' => 'Your company available balance is :available.'],
    'invitation' => [
        'title' => 'Invitation to join :organisation',
        'body' => 'You have been invited to create an account at :organisation.',
        'action' => 'Accept invitation',
        'expires' => 'The invitation expires on :date.',
    ],
];
