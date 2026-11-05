<?php

use Illuminate\Support\Facades\Schedule;

// Run with: php artisan schedule:work
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
