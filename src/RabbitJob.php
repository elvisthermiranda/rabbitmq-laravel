<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitJob extends Job implements JobContract
{
    protected RabbitService $rabbit;
    protected AMQPMessage $message;

    private bool $acked = false;
    private bool $nacked = false;

    public function __construct(
        Container $container,
        RabbitService $rabbit,
        AMQPMessage $message,
        string $queue,
        string $connectionName = 'rabbitmq'
    ) {
        $this->container = $container;
        $this->rabbit = $rabbit;
        $this->message = $message;
        $this->queue = $queue;
        $this->connectionName = $connectionName;
    }

    /**
     * ID único do job (usando delivery_tag do RabbitMQ).
     */
    public function getJobId()
    {
        return $this->message->getDeliveryTag();
    }

    /**
     * Retorna o corpo bruto do job.
     */
    public function getRawBody()
    {
        return $this->message->getBody();
    }

    /**
     * Nome da connection (usado pelo Laravel em failed_jobs).
     */
    public function getConnectionName()
    {
        return $this->connectionName;
    }

    /**
     * Confirma que o job foi processado com sucesso.
     */
    public function delete()
    {
        parent::delete();

        if ($this->message->getChannel()->is_open() && $this->message->getDeliveryTag()) {
            try {
                $this->message->getChannel()->basic_ack($this->message->getDeliveryTag());
            } catch (\Throwable $e) {
                Log::warning("Ack falhou: " . $e->getMessage());
            }
        }
    }

    /**
     * Reenvia o job para a fila (com ou sem delay).
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $body = json_decode($this->getRawBody(), true);
        $body['attempts'] = ($body['attempts'] ?? 1) + 1;

        $msg = new AMQPMessage(
            json_encode($body),
            ['delivery_mode' => 2]
        );

        if ($delay > 0) {
            $this->rabbit->channel()->queue_declare(
                $this->queue . '-delayed',
                false,
                true,
                false,
                false,
                false,
                [
                    'x-dead-letter-exchange'    => ['S', ''],
                    'x-dead-letter-routing-key' => ['S', $this->queue],
                ]
            );

            $msg->set('expiration', (string) ($delay * 1000));
            $this->rabbit->channel()->basic_publish($msg, '', $this->queue . '-delayed');
        } else {
            $this->rabbit->channel()->basic_publish($msg, '', $this->queue);
        }

        $this->delete();
    }

    /**
     * Marca como falhado e descarta sem requeue.
     */
    public function fail($e = null)
    {
        parent::fail($e);

        if (! $this->nacked && $this->message->getChannel()->is_open()) {
            $this->message->getChannel()->basic_nack(
                $this->message->getDeliveryTag(),
                false,
                false // não requeue
            );
            $this->nacked = true;
        }
    }

    public function attempts()
    {
        $body = json_decode($this->getRawBody(), true);
        return $body['attempts'] ?? 1;
    }
}
