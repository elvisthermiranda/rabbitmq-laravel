<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitService
{
    protected ?AMQPStreamConnection $connection = null;
    protected ?AMQPChannel $channel = null;

    protected array $exchangeConfig = [];

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

        $this->exchangeConfig = $config['exchange'] ?? [
            'name' => 'app_exchange',
            'type' => 'direct',
            'durable' => true,
            'auto_delete' => false,
            'args' => [],
        ];

        $this->setExchange(
            $this->exchangeConfig['name'],
            $this->exchangeConfig['type'],
            $this->exchangeConfig['durable'],
            $this->exchangeConfig['auto_delete'],
            $this->exchangeConfig['args'] ?? []
        );
    }

    protected function ensureConnected(): void
    {
        if (!$this->connection || ! $this->connection->isConnected() || ! $this->channel || ! $this->channel->is_open()) {
            Log::warning('RabbitMQ conexão/canal fechado, reconectando...');
            $this->reconnect();
        }
    }

    public function publish(string $queue, array $data, ?string $exchange = null, ?string $routingKey = null): void
    {
        $this->publishBatch($queue, [$data], $exchange, $routingKey);
    }

    public function publishBatch(string $queue, array $messages, ?string $exchange = null, ?string $routingKey = null): void
    {
        $this->ensureExchangeDeclared();

        $exchange = $exchange ?? $this->exchangeConfig['name'];
        // routing key ou queue name
        $rtk = $routingKey ?? $queue;

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);
        $this->channel->queue_bind($queue, $exchange, $rtk);

        foreach ($messages as $data) {
            $msg = new AMQPMessage(
                body: json_encode($data, JSON_UNESCAPED_UNICODE),
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => 2,
                ]
            );
            $this->channel->basic_publish($msg, $exchange, $rtk);
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
                'x-dead-letter-exchange'    => ['S', $this->exchangeConfig['name']],
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
    public function consume(string $queue, callable $callback, int $timeoutSegundos = 30, ?string $exchange = null, ?string $routingKey = null): void
    {
        $this->ensureExchangeDeclared();

        $exchange = $exchange ?? $this->exchangeConfig['name'];

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);
        $this->channel->queue_bind($queue, $exchange, $routingKey ?? $queue);
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

    // isso é apenas para devs testarem, consume 1 vez e finaliza.
    public function consumeOne(string $queue, callable $callback): void
    {
        $this->ensureExchangeDeclared();

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);

        if ($msg = $this->channel->basic_get($queue)) {
            $data = json_decode($msg->getBody(), true);
            $callback($data, $msg);
        }
    }

    public function setExchange(
        string $name = 'app_exchange',
        string $type = 'direct',
        bool $durable = true,
        bool $autoDelete = false,
        array $args = []
    ): self
    {
        $this->ensureConnected();

        if ($type === 'x-delayed-message' && ! isset($args['x-delayed-type'])) {
            $args['x-delayed-type'] = ['S', 'direct'];
        }

        $this->channel->exchange_declare(
            $name,
            $type,
            false,
            $durable,
            $autoDelete,
            false,
            $args
        );

        $this->exchangeConfig = compact('name', 'type', 'durable', 'autoDelete', 'args');

        return $this;
    }

    protected function ensureExchangeDeclared(): void
    {
        $ex = $this->exchangeConfig;

        $this->channel->exchange_declare(
            $ex['name'],
            $ex['type'],
            false,
            $ex['durable'] ?? true,
            $ex['auto_delete'] ?? false,
            false,
            $ex['args']
        );
    }

    /**
     * Publica mensagem com delay usando o plugin rabbitmq_delayed_message_exchange
     *
     * @param string $queue
     * @param array $data
     * @param int $delayMs Delay em milissegundos
     * @param string|null $routingKey
     */
    public function publishDelayed(string $queue, array $data, int $delayMs, ?string $routingKey = null): void
    {
        $this->ensureConnected();

        // garante que o exchange delayed existe
        $this->channel->exchange_declare(
            'delayed_exchange',
            'x-delayed-message',
            false,
            true,
            false,
            false,
            false,
            [
                'x-delayed-type' => ['S', 'direct'],
            ]
        );

        $rtk = $routingKey ?? $queue;

        $this->channel->queue_declare($queue, durable: true, exclusive: false, auto_delete: false);
        $this->channel->queue_bind($queue, 'delayed_exchange', $rtk);

        $headers = new AMQPTable([
            'x-delay' => $delayMs,
        ]);

        $msg = new AMQPMessage(
            json_encode($data, JSON_UNESCAPED_UNICODE),
            [
                'delivery_mode' => 2,
                'application_headers' => $headers,
                'content_type' => 'application/json',
            ]
        );

        $this->channel->basic_publish($msg, 'delayed_exchange', $rtk);
    }

}
