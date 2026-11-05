<?php

namespace App\Events;

class OrderCancelled extends OrderEvent
{
    public function action(): string
    {
        return 'order.cancelled';
    }
}
