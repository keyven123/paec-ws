<?php

namespace App\Observers;

use App\Models\Transaction;
use Illuminate\Support\Str;

class TransactionObserver
{
    public function creating(Transaction $transaction): void
    {
        if (empty($transaction->uuid)) {
            $transaction->uuid = (string) Str::uuid();
        }
        $transaction->created_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
        $transaction->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }

    public function updating(Transaction $transaction): void
    {
        $transaction->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }
}
