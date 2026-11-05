<?php

namespace App\Events;

class OrderRejected extends OrderEvent
{
    public function action(): string
    {
        return 'order.rejected';
    }
}
