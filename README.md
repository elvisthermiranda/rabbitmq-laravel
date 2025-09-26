# RabbitMQ Laravel Queue Driver

[![Laravel](https://img.shields.io/badge/Laravel-10%2F11%2F12-red)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Um pacote Laravel que adiciona suporte ao **RabbitMQ** como driver de queue, permitindo rodar jobs com o comando nativo:

```bash
php artisan queue:work rabbitmq
```

---

## 📦 Instalação

Adicione o pacote ao seu projeto Laravel (10, 11 ou 12):

```bash
composer require elvisthermiranda/rabbitmq-laravel
```

> 💡 Para testes locais sem publicar no Packagist, use o recurso de **path repository** no seu `composer.json`:
>
> ```json
> "repositories": [
>     {
>         "type": "path",
>         "url": "../rabbitmq-laravel"
>     }
> ]
> ```
>
> E depois rode:
> ```bash
> composer require elvisthermiranda/rabbitmq-laravel:dev-main
> ```

---

## ⚙️ Configuração

Adicione no arquivo `config/queue.php` uma nova conexão `rabbitmq`:

```php
'rabbitmq' => [
    'driver'   => 'rabbitmq',
    'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
    'port'     => env('RABBITMQ_PORT', 5672),
    'username' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),
    'queue'    => env('RABBITMQ_QUEUE', 'default'),
],
```

No seu arquivo `.env`, configure:

```env
QUEUE_CONNECTION=rabbitmq

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_QUEUE=default
```

---

## 🚀 Uso

Crie um job normalmente:

```bash
php artisan make:job ProcessExampleJob
```

```php
// app/Jobs/ProcessExampleJob.php
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

Rode o worker:

```bash
php artisan queue:work rabbitmq
```

---

## 🛠 Dependências

- [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib) – cliente oficial AMQP em PHP  
- Illuminate components: `support`, `contracts`, `queue`, `container`

---

## 📖 Roadmap

- [x] Suporte básico ao RabbitMQ (`push`, `pop`, `ack`, `nack`)  
- [ ] Implementar suporte a **delay** (via exchanges DLX ou plugin `delayed_message_exchange`)  
- [ ] Suporte a múltiplos exchanges e routing keys  
- [ ] Testes automatizados  

---

## 📜 Licença

Este pacote é licenciado sob a [MIT License](LICENSE).
