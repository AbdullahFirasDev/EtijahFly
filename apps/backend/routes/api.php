<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Partner;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EtijahFly API v1
|--------------------------------------------------------------------------
| public    : no authentication (login, password reset, invitations, branding)
| common    : any authenticated user (profile, sessions, notifications, reference data)
| partner/* : agency users, partner-portal tokens, tenant-scoped
| admin/*   : platform users, admin-portal tokens, permission-checked per endpoint
*/

Route::prefix('v1')->name('v1.')->group(function () {
    // ---------------------------------------------------------------- public
    Route::middleware('throttle:public')->group(function () {
        Route::get('health', [PublicController::class, 'health'])->name('health');
        Route::get('public/branding', [PublicController::class, 'branding'])->name('public.branding');
        Route::get('public/tenants/{slug}/logo', [PublicController::class, 'logo'])->name('public.logo');
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
    });
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('auth.forgot');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('auth.reset');

    // ---------------------------------------------------------------- authenticated
    Route::middleware(['auth:sanctum', 'session.active', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/sessions', [AuthController::class, 'sessions'])->name('auth.sessions');
        Route::delete('auth/sessions/{session}', [AuthController::class, 'revokeSession'])->whereNumber('session')->name('auth.sessions.revoke');
        Route::put('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification')->name('notifications.read');

        Route::get('reference/airports', [ReferenceController::class, 'airports'])->name('reference.airports');
        Route::get('reference/airlines', [ReferenceController::class, 'airlines'])->name('reference.airlines');

        // ------------------------------------------------------------ partner portal
        Route::prefix('partner')->name('partner.')->middleware(['portal:partner', 'tenant'])->group(function () {
            Route::get('dashboard', Partner\DashboardController::class)->name('dashboard');

            Route::post('flights/search', [Partner\FlightSearchController::class, 'search'])->middleware('throttle:search')->name('flights.search');
            Route::get('flights/offers/{offer}', [Partner\FlightSearchController::class, 'show'])->name('flights.offers.show');
            Route::post('flights/offers/{offer}/price-check', [Partner\FlightSearchController::class, 'priceCheck'])->name('flights.offers.price-check');

            Route::get('bookings', [Partner\BookingController::class, 'index'])->name('bookings.index');
            Route::post('bookings', [Partner\BookingController::class, 'store'])->middleware('idempotent')->name('bookings.store');
            Route::get('bookings/{booking}', [Partner\BookingController::class, 'show'])->name('bookings.show');
            Route::post('bookings/{booking}/issue', [Partner\BookingController::class, 'issue'])->middleware('idempotent')->name('bookings.issue');
            Route::post('bookings/{booking}/cancel', [Partner\BookingController::class, 'cancel'])->name('bookings.cancel');
            Route::post('bookings/{booking}/refund', [Partner\BookingController::class, 'refund'])->middleware('idempotent')->name('bookings.refund');
            Route::post('bookings/{booking}/documents/{type}', [Partner\BookingController::class, 'generateDocument'])->whereIn('type', ['booking_confirmation', 'e_ticket'])->name('bookings.documents.generate');
            Route::get('bookings/{booking}/documents/{document}/url', [Partner\BookingController::class, 'documentUrl'])->name('bookings.documents.url');
            Route::get('bookings/{booking}/documents/{document}/file', [Partner\BookingController::class, 'documentFile'])->name('bookings.documents.file');

            Route::apiResource('travelers', Partner\TravelerController::class);

            Route::get('finance/account', [Partner\FinanceController::class, 'account'])->name('finance.account');
            Route::get('finance/statement', [Partner\FinanceController::class, 'statement'])->name('finance.statement');
            Route::get('finance/payments', [Partner\FinanceController::class, 'payments'])->name('finance.payments');
            Route::post('finance/payments', [Partner\FinanceController::class, 'storePayment'])->middleware('idempotent')->name('finance.payments.store');
            Route::get('finance/invoices', [Partner\FinanceController::class, 'invoices'])->name('finance.invoices');
            Route::get('finance/invoices/{invoice}/file', [Partner\FinanceController::class, 'invoiceFile'])->name('finance.invoices.file');
            Route::get('finance/commissions', [Partner\FinanceController::class, 'commissions'])->name('finance.commissions');

            Route::get('reports/summary', [Partner\ReportController::class, 'summary'])->name('reports.summary');

            Route::get('team/users', [Partner\TeamController::class, 'users'])->name('team.users');
            Route::post('team/invitations', [Partner\TeamController::class, 'invite'])->name('team.invite');
            Route::get('team/invitations', [Partner\TeamController::class, 'invitations'])->name('team.invitations');
            Route::delete('team/invitations/{invitation}', [Partner\TeamController::class, 'revokeInvitation'])->whereNumber('invitation')->name('team.invitations.revoke');
            Route::put('team/users/{user}/roles', [Partner\TeamController::class, 'updateRoles'])->name('team.users.roles');
            Route::put('team/users/{user}/status', [Partner\TeamController::class, 'updateStatus'])->name('team.users.status');
            Route::get('team/roles', [Partner\TeamController::class, 'roles'])->name('team.roles');
            Route::get('team/permissions', [Partner\TeamController::class, 'permissions'])->name('team.permissions');
            Route::post('team/roles', [Partner\TeamController::class, 'storeRole'])->name('team.roles.store');
            Route::put('team/roles/{role}', [Partner\TeamController::class, 'updateRole'])->whereNumber('role')->name('team.roles.update');
            Route::delete('team/roles/{role}', [Partner\TeamController::class, 'destroyRole'])->whereNumber('role')->name('team.roles.destroy');

            Route::get('settings/branding', [Partner\BrandingController::class, 'show'])->name('branding.show');
            Route::put('settings/branding', [Partner\BrandingController::class, 'update'])->name('branding.update');
            Route::post('settings/branding/logo', [Partner\BrandingController::class, 'uploadLogo'])->name('branding.logo');
        });

        // ------------------------------------------------------------ admin portal
        Route::prefix('admin')->name('admin.')->middleware('portal:admin')->group(function () {
            Route::get('dashboard', [Admin\ReportController::class, 'dashboard'])->name('dashboard');

            Route::get('tenants', [Admin\TenantController::class, 'index'])->name('tenants.index');
            Route::post('tenants', [Admin\TenantController::class, 'store'])->name('tenants.store');
            Route::get('tenants/{tenant}', [Admin\TenantController::class, 'show'])->name('tenants.show');
            Route::patch('tenants/{tenant}', [Admin\TenantController::class, 'update'])->name('tenants.update');
            Route::post('tenants/{tenant}/suspend', [Admin\TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('tenants/{tenant}/activate', [Admin\TenantController::class, 'activate'])->name('tenants.activate');
            Route::put('tenants/{tenant}/credit-limit', [Admin\TenantController::class, 'creditLimit'])->name('tenants.credit-limit');
            Route::put('tenants/{tenant}/account-status', [Admin\TenantController::class, 'accountStatus'])->name('tenants.account-status');

            Route::get('users', [Admin\PlatformUserController::class, 'index'])->name('users.index');
            Route::post('users/invitations', [Admin\PlatformUserController::class, 'invite'])->name('users.invite');
            Route::get('users/invitations', [Admin\PlatformUserController::class, 'invitations'])->name('users.invitations');
            Route::put('users/{user}/roles', [Admin\PlatformUserController::class, 'updateRoles'])->whereNumber('user')->name('users.roles');
            Route::put('users/{user}/status', [Admin\PlatformUserController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
            Route::get('roles', [Admin\PlatformUserController::class, 'roles'])->name('roles.index');
            Route::get('permissions', [Admin\PlatformUserController::class, 'permissions'])->name('permissions.index');
            Route::post('roles', [Admin\PlatformUserController::class, 'storeRole'])->name('roles.store');
            Route::delete('roles/{role}', [Admin\PlatformUserController::class, 'destroyRole'])->whereNumber('role')->name('roles.destroy');

            Route::get('providers', [Admin\ProviderController::class, 'index'])->name('providers.index');
            Route::patch('providers/{provider:code}', [Admin\ProviderController::class, 'update'])->name('providers.update');
            Route::post('providers/{provider:code}/health-check', [Admin\ProviderController::class, 'healthCheck'])->name('providers.health');
            Route::get('airlines', [Admin\ProviderController::class, 'airlines'])->name('airlines.index');
            Route::post('airlines/import', [Admin\ProviderController::class, 'importAirlines'])->middleware('throttle:10,1')->name('airlines.import');

            Route::get('pricing-rules', [Admin\PricingController::class, 'index'])->name('pricing.index');
            Route::post('pricing-rules', [Admin\PricingController::class, 'store'])->name('pricing.store');
            Route::put('pricing-rules/{rule}', [Admin\PricingController::class, 'update'])->name('pricing.update');
            Route::delete('pricing-rules/{rule}', [Admin\PricingController::class, 'destroy'])->name('pricing.destroy');
            Route::post('pricing-rules/preview', [Admin\PricingController::class, 'preview'])->name('pricing.preview');
            Route::get('exchange-rates', [Admin\PricingController::class, 'exchangeRates'])->name('fx.index');
            Route::post('exchange-rates', [Admin\PricingController::class, 'storeExchangeRate'])->name('fx.store');

            Route::get('bookings', [Admin\BookingController::class, 'index'])->name('bookings.index');
            Route::get('bookings/{booking}', [Admin\BookingController::class, 'show'])->name('bookings.show');
            Route::post('bookings/{booking}/verify-ticketing', [Admin\BookingController::class, 'verifyTicketing'])->name('bookings.verify');
            Route::post('bookings/{booking}/cancel', [Admin\BookingController::class, 'cancel'])->name('bookings.cancel');
            Route::get('bookings/{booking}/documents/{document}/file', [Admin\BookingController::class, 'documentFile'])->name('bookings.documents.file');
            Route::get('refunds', [Admin\BookingController::class, 'refunds'])->name('refunds.index');
            Route::post('refunds/{refund}/complete', [Admin\BookingController::class, 'completeRefund'])->name('refunds.complete');
            Route::post('refunds/{refund}/reject', [Admin\BookingController::class, 'rejectRefund'])->name('refunds.reject');

            Route::get('finance/accounts', [Admin\FinanceController::class, 'accounts'])->name('finance.accounts');
            Route::get('finance/accounts/{account}/statement', [Admin\FinanceController::class, 'statement'])->name('finance.statement');
            Route::get('finance/transactions/{transaction}', [Admin\FinanceController::class, 'transaction'])->name('finance.transaction');
            Route::get('finance/payments', [Admin\FinanceController::class, 'payments'])->name('finance.payments');
            Route::get('finance/payments/{payment}/proof', [Admin\FinanceController::class, 'paymentProof'])->name('finance.payments.proof');
            Route::post('finance/payments/{payment}/approve', [Admin\FinanceController::class, 'approvePayment'])->name('finance.payments.approve');
            Route::post('finance/payments/{payment}/reject', [Admin\FinanceController::class, 'rejectPayment'])->name('finance.payments.reject');
            Route::get('finance/adjustments', [Admin\FinanceController::class, 'adjustments'])->name('finance.adjustments');
            Route::post('finance/adjustments', [Admin\FinanceController::class, 'storeAdjustment'])->name('finance.adjustments.store');
            Route::post('finance/adjustments/{adjustment}/approve', [Admin\FinanceController::class, 'approveAdjustment'])->name('finance.adjustments.approve');
            Route::post('finance/adjustments/{adjustment}/reject', [Admin\FinanceController::class, 'rejectAdjustment'])->name('finance.adjustments.reject');
            Route::get('finance/invoices', [Admin\FinanceController::class, 'invoices'])->name('finance.invoices');

            Route::get('reports/summary', [Admin\ReportController::class, 'summary'])->name('reports.summary');
            Route::get('reports/commissions', [Admin\ReportController::class, 'commissions'])->name('reports.commissions');
            Route::get('reports/receivables', [Admin\ReportController::class, 'receivables'])->name('reports.receivables');

            Route::get('audit-logs', [Admin\SystemController::class, 'auditLogs'])->name('audit.index');
            Route::get('system/health', [Admin\SystemController::class, 'health'])->name('system.health');
            Route::get('system/provider-logs', [Admin\SystemController::class, 'providerLogs'])->name('system.provider-logs');
            Route::get('system/failed-jobs', [Admin\SystemController::class, 'failedJobs'])->name('system.failed-jobs');
        });
    });
});
