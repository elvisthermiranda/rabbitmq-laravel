<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;

class RabbitService
{
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;
    private string $exchange = 'app_exchange';

    public function __construct()
    {
        $this->connect();
    }

    protected function connect(): void
    {
        $config = config('queue.connections.rabbitmq');

        $this->connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['username'],
            $config['password'],
            $config['vhost']
        );

        $this->channel = $this->connection->channel();

        // Acréscimo
        $this->channel->exchange_declare(
            $this->exchange,
            'direct',
            false,
            true,
            false
        );
    }

    protected function ensureConnected(): void
    {
        if (!$this->connection || ! $this->connection->isConnected() || ! $this->channel || ! $this->channel->is_open()) {
            Log::warning('RabbitMQ conexão/canal fechado, reconectando...');
            $this->reconnect();
        }
    }

    public function publish(string $queue, array $data, ?string $exchange = null): void
    {
        $this->publishBatch($queue, [$data], $exchange);
    }

    public function publishBatch(string $queue, array $messages, ?string $exchange = null): void
    {
        $this->ensureConnected();

        $exchange = $exchange ?? $this->exchange;

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);
        $this->channel->queue_bind($queue, $exchange, $queue);

        foreach ($messages as $data) {
            $msg = new AMQPMessage(
                body: json_encode($data, JSON_UNESCAPED_UNICODE),
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => 2,
                ]
            );
            $this->channel->basic_publish($msg, $exchange, $queue);
        }
    }

    public function channel()
    {
        return $this->channel;
    }

    public function later(string $queue, array $data, int $delaySegundos): void
    {
        $this->ensureConnected();

        // declara a fila delay (se não existir)
        $this->channel->queue_declare(
            $queue . '-delayed',
            false,
            true,
            false,
            false,
            false,
            [
                'x-dead-letter-exchange'    => ['S', ''],
                'x-dead-letter-routing-key' => ['S', $queue],
            ]
        );

        $msg = new AMQPMessage(
            json_encode($data, JSON_UNESCAPED_UNICODE),
            [
                'delivery_mode' => 2,
                'expiration'    => $delaySegundos * 1000, // RabbitMQ exige em ms
            ]
        );

        // publica na fila delayed
        $this->channel->basic_publish($msg, '', $queue . '-delayed');
    }

    /**
     * Consome mensagens de uma fila em loop com reconexão automática e timeout.
     *
     * @param string   $queue
     * @param callable $callback
     * @param int      $timeoutSegundos Tempo máximo de espera sem mensagens antes de reconectar
     */
    public function consume(string $queue, callable $callback, int $timeoutSegundos = 30, ?string $exchange = null): void
    {
        $this->ensureConnected();

        $exchange = $exchange ?? $this->exchange;

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);
        $this->channel->queue_bind($queue, $exchange, $queue);
        $this->channel->basic_qos(null, 1, null);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function ($msg) use ($callback) {
                $data = json_decode($msg->body, true);
                $callback($data, $msg);
            }
        );

        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait(null, false, $timeoutSegundos);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                Log::warning("RabbitMQ timeout de {$timeoutSegundos}s sem mensagens, verificando conexão...");
                $this->connect(); // força reconexão periódica
            } catch (\Throwable $e) {
                Log::error("Erro no consumo RabbitMQ: {$e->getMessage()}");
                $this->connect();
            }
        }
    }

    public function ack(AMQPMessage $msg): void
    {
        $msg->ack();
    }

    public function nack(AMQPMessage $msg, bool $requeue = false): void
    {
        $msg->nack(false, $requeue);
    }

    public function close(): void
    {
        if ($this->channel && $this->channel->is_open()) {
            $this->channel->close();
        }
        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function declareQueue(string $queue): void
    {
        $this->channel->queue_declare(
            $queue,     // nome da fila
            false,      // passive
            true,       // durable
            false,      // exclusive
            false       // auto_delete
        );
    }

    public function reconnect(): void
    {
        $this->close();
        $this->connect();
        Log::info('RabbitMQ reconectado com sucesso.');
    }
}
