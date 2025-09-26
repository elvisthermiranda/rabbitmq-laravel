<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\QueueManager;

class RabbitServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app->make(QueueManager::class)->addConnector('rabbitmq', function () {
            return new RabbitConnector;
        });
    }
}
