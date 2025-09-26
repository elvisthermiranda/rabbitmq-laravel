<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Queue;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;

class RabbitQueue extends Queue implements QueueContract
{
    public function __construct(
        protected RabbitService $rabbit,
        protected string $defaultQueue = 'default'
    ) {}

    public function size($queue = null): int
    {
        // RabbitMQ não expõe tamanho de fila sem management API
        return 0;
    }

    public function push($job, $data = '', $queue = null)
    {
        $queue = $queue ?: $this->defaultQueue;

        $payload = json_decode($this->createPayload($job, $this->getQueue($queue), $data), true);

        // inicializa attempts
        if (! isset($payload['attempts'])) {
            $payload['attempts'] = 1;
        }

        $this->safePublish($queue, $payload);

        return true;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $queue = $this->getQueue($queue);
        $payload = $this->createPayload($job, $queue, $data);

        $msg = new AMQPMessage(
            $payload,
            [
                'delivery_mode' => 2, // persistente
                'expiration'    => (string) ($delay * 1000), // ms
            ]
        );

        $this->safeRun(function () use ($msg, $queue) {
            $this->rabbit->channel()->basic_publish($msg, '', $queue . '-delayed');
        });
    }

    public function pop($queue = null): ?Job
    {
        $queue = $this->getQueue($queue);

        return $this->safeRun(function () use ($queue) {
            $this->rabbit->channel()->queue_declare(
                $queue,
                durable: true,
                exclusive: false,
                auto_delete: false
            );

            $msg = $this->rabbit->channel()->basic_get($queue);

            if ($msg) {
                return new RabbitJob(
                    $this->container,
                    $this->rabbit,
                    $msg,
                    $queue,
                    'rabbitmq'
                );
            }

            return null;
        });
    }

    protected function getQueue($queue): string
    {
        return $queue ?: $this->defaultQueue;
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueue($queue);
        $this->safePublish($queue, json_decode($payload, true));
        return true;
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ((array) $jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    /**
     * Executa algo e tenta reconectar se canal/conexão estiver fechado.
     */
    protected function safeRun(callable $callback)
    {
        try {
            return $callback();
        } catch (AMQPChannelClosedException|AMQPConnectionClosedException $e) {
            // tenta reconectar
            $this->rabbit->reconnect();
            return $callback();
        }
    }

    /**
     * Publica mensagens com retry.
     */
    protected function safePublish(string $queue, array $payload): void
    {
        $this->safeRun(function () use ($queue, $payload) {
            $this->rabbit->publish($queue, $payload);
        });
    }
}
