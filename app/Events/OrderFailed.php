<?php

namespace App\Events;

class OrderFailed extends OrderEvent
{
    public function action(): string
    {
        return 'order.failed';
    }
}
