<?php

namespace ElvistherMiranda\RabbitmqLaravel;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ElvistherMiranda\RabbitmqLaravel\RabbitService
 *
 * @method static void publish(string $queue, array $data, ?string $exchange = null, ?string $routingKey = null)
 * @method static void publishBatch(string $queue, array $messages, ?string $exchange = null, ?string $routingKey = null)
 * @method static void push(string $queue, array $data, ?string $exchange = null, ?string $routingKey = null)
 * @method static void pushBatch(string $queue, array $messages, ?string $exchange = null, ?string $routingKey = null)
 * @method static void consume(string $queue, callable $callback, int $timeoutSegundos = 30, ?string $exchange = null, ?string $routingKey = null)
 * @method static void consumeOne(string $queue, callable $callback)
 * @method static void ack(\PhpAmqpLib\Message\AMQPMessage $msg)
 * @method static void nack(\PhpAmqpLib\Message\AMQPMessage $msg, bool $requeue = false)
 * @method static void remove(\PhpAmqpLib\Message\AMQPMessage $msg)
 * @method static void later(string $queue, array $data, int $delaySegundos)
 * @method static void declareQueue(string $queue)
 * @method static void reconnect()
 * @method static void close()
 * @method static self setExchange(string $name = 'app_exchange', string $type = 'direct', bool $durable = true, bool $autoDelete = false, array $args = [])
 */
class Rabbit extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RabbitService::class;
    }

    public static function push(string $queue, array $data, ?string $exchange = null, ?string $routingKey = null): void
    {
        static::getFacadeRoot()->publish($queue, $data, $exchange, $routingKey);
    }

    public static function pushBatch(string $queue, array $messages, ?string $exchange = null, ?string $routingKey = null): void
    {
        static::getFacadeRoot()->publishBatch($queue, $messages, $exchange, $routingKey);
    }
}
