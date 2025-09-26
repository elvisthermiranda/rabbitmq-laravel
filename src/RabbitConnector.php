<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use Illuminate\Queue\Connectors\ConnectorInterface;

class RabbitConnector implements ConnectorInterface
{
    public function connect(array $config)
    {
        return new RabbitQueue(
            app(RabbitService::class),
            $config['queue'] ?? 'default'
        );
    }
}
