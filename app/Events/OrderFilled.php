<?php

namespace App\Events;

class OrderFilled extends OrderEvent
{
    public function action(): string
    {
        return 'order.filled';
    }
}
