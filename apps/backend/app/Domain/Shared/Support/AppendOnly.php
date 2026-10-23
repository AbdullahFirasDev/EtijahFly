<?php

namespace App\Domain\Shared\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Application-level mirror of the database append-only triggers (PostgresHardening).
 *
 * @mixin Model
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only.'));
    }
}
