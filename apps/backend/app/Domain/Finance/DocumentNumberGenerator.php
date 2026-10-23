<?php

namespace App\Domain\Finance;

use Illuminate\Database\ConnectionInterface;

/** Gap-free, per-year sequential numbers (INV-2026-000001) via an atomic upsert. */
class DocumentNumberGenerator
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function next(string $sequence, string $prefix): string
    {
        $year = (int) now()->format('Y');

        $row = $this->db->selectOne(
            'INSERT INTO document_sequences (name, year, last_number) VALUES (?, ?, 1)
             ON CONFLICT (name, year) DO UPDATE SET last_number = document_sequences.last_number + 1
             RETURNING last_number',
            [$sequence, $year],
        );

        return sprintf('%s-%d-%06d', $prefix, $year, $row->last_number);
    }
}
