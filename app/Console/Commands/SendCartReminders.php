<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Notifications\CartReminderNotification;
use Illuminate\Console\Command;

class SendCartReminders extends Command
{
    protected $signature = 'app:send-cart-reminders';

    protected $description = 'Email customers whose cart has waited an hour since their last change without being paid';

    public const WAIT_MINUTES = 60;

    public function handle(): int
    {
        $carts = Cart::query()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('last_changed_at')
            ->where('last_changed_at', '<=', now()->subMinutes(self::WAIT_MINUTES))
            ->whereHas('items')
            ->with('user')
            ->get();

        foreach ($carts as $cart) {
            $cart->user->notify(new CartReminderNotification($cart->uuid));
            $cart->update(['reminder_sent_at' => now()]);
        }

        $this->info("Sent {$carts->count()} cart reminder(s).");

        return self::SUCCESS;
    }
}
