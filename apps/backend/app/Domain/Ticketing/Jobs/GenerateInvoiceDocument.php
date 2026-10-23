<?php

namespace App\Domain\Ticketing\Jobs;

use App\Domain\Finance\Models\Invoice;
use App\Domain\Ticketing\DocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoiceDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $invoiceId) {}

    public function handle(DocumentService $documents): void
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->find($this->invoiceId);
        if ($invoice) {
            $documents->generateInvoice($invoice);
        }
    }
}
