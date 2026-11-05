<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Who may do what with an order. Laravel finds this class automatically by name
 * (Order model -> OrderPolicy). A false answer becomes a 403 response.
 */
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}
