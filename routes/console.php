<?php

use App\Console\Commands\SyncCottageAvailability;
 use Illuminate\Support\Facades\Schedule;

   Schedule::command(SyncCottageAvailability::class)
       ->everyThirtyMinutes()
       ->withoutOverlapping()
       ->onOneServer();