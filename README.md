# RabbitMQ Laravel Queue Driver

[![Laravel](https://img.shields.io/badge/Laravel-10%2F11%2F12-red)](https://laravel.com)  
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://php.net)  
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Um pacote Laravel que adiciona suporte ao **RabbitMQ** como driver de queue, permitindo rodar jobs com o comando nativo:

```bash
php artisan queue:work rabbitmq
```

Também oferece uma **facade `Rabbit`** para publicar e consumir mensagens diretamente, com suporte a **exchanges, routing keys e delays**.

---

## 📦 Instalação

```bash
composer require elvisthermiranda/rabbitmq-laravel
```

---

## ⚙️ Configuração

No `config/queue.php` adicione a conexão `rabbitmq`:

```php
'rabbitmq' => [
    'driver'   => 'rabbitmq',
    'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
    'port'     => env('RABBITMQ_PORT', 5672),
    'username' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),
    'queue'    => env('RABBITMQ_QUEUE', 'default'),

    'exchange' => [
        'name'        => env('RABBITMQ_EXCHANGE', 'app_exchange'),
        'type'        => env('RABBITMQ_EXCHANGE_TYPE', 'direct'),
        'durable'     => true,
        'auto_delete' => false,
        'args'        => [],
    ],
],
```

Arquivo `.env`:

```env
QUEUE_CONNECTION=rabbitmq

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
RABBITMQ_EXCHANGE=app_exchange
RABBITMQ_EXCHANGE_TYPE=direct
```

---

## 🚀 Uso com Jobs do Laravel

Crie um job normalmente:

```bash
php artisan make:job ProcessExampleJob
```

```php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ProcessExampleJob implements ShouldQueue
{
    public function handle()
    {
        Log::info("RabbitMQ job executado com sucesso!");
    }
}
```

Dispare o job:

```php
ProcessExampleJob::dispatch();
```

Execute:

```bash
php artisan queue:work rabbitmq
```

---

## 🧩 Uso direto com a Facade `Rabbit`

### 1. Publicar mensagem
```php
Rabbit::push('example', ['msg' => 'olá mundo']);
```

### 2. Publicar várias mensagens
```php
Rabbit::pushBatch('example', [
    ['msg' => 'primeira'],
    ['msg' => 'segunda'],
]);
```

### 3. Consumir mensagens
```php
Rabbit::consume('example', function ($data, $msg) {
    echo "Mensagem recebida: " . json_encode($data) . PHP_EOL;
    Rabbit::ack($msg);
});
```

### 4. Consumir uma única mensagem (teste)
```php
Rabbit::consumeOne('example', function ($data, $msg) {
    echo "Mensagem única: " . json_encode($data) . PHP_EOL;
    Rabbit::ack($msg);
});
```

### 5. NACK com requeue
```php
Rabbit::consume('example', function ($data, $msg) {
    if (!isset($data['processar'])) {
        Rabbit::nack($msg, true); // devolve para a fila
        return;
    }

    Rabbit::ack($msg);
});
```

---

## ⏳ Mensagens com Delay

### 1. Delay básico (TTL + DLX)
Funciona em qualquer RabbitMQ, cria uma fila `example-delayed` automaticamente:

```php
Rabbit::later('example', ['msg' => 'executar daqui a 10s'], 10);
```

### 2. Delay com Plugin Oficial (`x-delayed-message`)
Mais flexível, cada mensagem pode ter um delay diferente.

Habilite o plugin no RabbitMQ:
```bash
rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

Uso:
```php
Rabbit::publishDelayed('example', ['msg' => 'executar daqui a 5s'], 5000);
```

Com routing key customizada:
```php
Rabbit::publishDelayed('emails', ['msg' => 'bem-vindo'], 10000, 'email.welcome');
```

---

## 🛠 Dependências

- [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib)  
- Illuminate components: `support`, `contracts`, `queue`, `container`

---

## 📖 Roadmap

- [x] Suporte básico ao RabbitMQ (`push`, `pop`, `ack`, `nack`)  
- [x] Suporte a **delay via TTL/DLX**  
- [x] Suporte a **delay via plugin oficial (`x-delayed-message`)**  
- [x] Suporte a múltiplos exchanges e routing keys  
- [ ] Testes automatizados  

---

## 📜 Licença

MIT License — veja o arquivo [LICENSE](LICENSE).
