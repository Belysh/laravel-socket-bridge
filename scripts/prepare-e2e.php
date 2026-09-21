<?php

// Only prepares the disposable fixture used by scripts/e2e.mjs, never a host app.
declare(strict_types=1);

$root = dirname(__DIR__);
$app = getenv('SOCKET_BRIDGE_E2E_APP') ?: $root.'/.test-results/laravel-app';
if (! str_starts_with($app, $root.'/.test-results/') || str_contains($app, '/../')) {
    throw new RuntimeException('E2E fixtures must be inside this package\'s .test-results directory.');
}
if (! is_file($app.'/artisan')) {
    fwrite(STDERR, "Create the disposable Laravel 13 app at .test-results/laravel-app first.\n");
    exit(1);
}

$provider = <<<'PHP'
<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Broadcast;
use SocketBridge\Commands\CommandRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(CommandRegistry $commands): void
    {
        if (! app()->environment(['local', 'testing'])) return;
        Broadcast::channel('demo.{userId}', fn ($user, $userId) => (string) $user->id === (string) $userId);
        Broadcast::channel('App.Models.User.{userId}', fn ($user, $userId) => (string) $user->id === (string) $userId);
        Broadcast::channel('team.{team}', fn ($user, $team) => $team === 'allowed' ? ['name' => $user->name] : false);
        $commands->register('demo.note.create', \App\BridgeDemo\CreateNote::class);
    }
}
PHP;

$handler = <<<'PHP'
<?php
namespace App\BridgeDemo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Facades\Socket;

final class CreateNote implements CommandHandler
{
    public function handle(array $payload, CommandContext $context): array
    {
        $validated = Validator::make($payload, ['text' => 'required|string|max:200'])->validate();
        $id = DB::table('demo_notes')->insertGetId(['user_id' => $context->userId, 'text' => $validated['text']]);
        $note = ['id' => $id, 'text' => $validated['text']];
        Socket::durable()->toRoom('private-demo.'.$context->userId)
            ->exceptSocket($context->socketId)->emit('demo.note.created', ['note' => $note]);
        return $note;
    }
}
PHP;

$event = <<<'PHP'
<?php
namespace App\BridgeDemo;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class DemoUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    public function __construct(public string $userId, public string $text) {}
    public function broadcastOn(): array { return [new PrivateChannel('demo.'.$this->userId)]; }
    public function broadcastAs(): string { return 'demo.updated'; }
    public function broadcastWith(): array { return ['text' => $this->text]; }
}
PHP;

$notification = <<<'PHP'
<?php
namespace App\BridgeDemo;

use Illuminate\Notifications\Notification;

final class DemoNotification extends Notification
{
    public function via($notifiable): array { return ['broadcast']; }
    public function toArray($notifiable): array { return ['message' => 'Notification from Laravel']; }
}
PHP;

$routes = <<<'PHP'
<?php
use App\Models\User;
use App\BridgeDemo\DemoUpdated;
use App\BridgeDemo\DemoQueued;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use SocketBridge\Facades\Socket;

// Test-only login shortcuts. This file exists exclusively in the disposable fixture.
if (! app()->environment(['local', 'testing'])) return;
Route::get('/demo/login/{id}', function (int $id) {
    abort_unless(in_array($id, [1, 2]), 404);
    Auth::login(User::findOrFail($id));
    request()->session()->regenerate();
    return ['csrf' => csrf_token()];
});
Route::middleware('auth')->group(function () {
    Route::post('/demo/broadcast', function (Request $request) {
        broadcast(new DemoUpdated((string) $request->user()->id, 'native broadcaster'))->toOthers();
        return ['ok' => true];
    });
    Route::post('/demo/direct', function (Request $request) {
        Socket::toUser($request->user()->id)->emit('demo.direct', ['ok' => true]);
        return ['ok' => true];
    });
    Route::post('/demo/notification', function (Request $request) {
        $request->user()->notify(new \App\BridgeDemo\DemoNotification);
        return ['ok' => true];
    });
    Route::post('/demo/queued', function (Request $request) {
        broadcast(new DemoQueued((string) $request->user()->id, 'queued broadcaster'))->toOthers();
        return ['ok' => true];
    });
    Route::post('/demo/revoke', function (Request $request) {
        Socket::disconnectUser($request->user()->id);
        return ['ok' => true];
    });
    Route::get('/demo/notes', fn () => ['count' => DB::table('demo_notes')->count()]);
});
PHP;

@mkdir($app.'/app/BridgeDemo', 0777, true);
file_put_contents($app.'/app/Providers/AppServiceProvider.php', $provider);
file_put_contents($app.'/app/BridgeDemo/CreateNote.php', $handler);
file_put_contents($app.'/app/BridgeDemo/DemoUpdated.php', $event);
file_put_contents($app.'/app/BridgeDemo/DemoNotification.php', $notification);
file_put_contents($app.'/app/BridgeDemo/DemoQueued.php', str_replace(['DemoUpdated', 'ShouldBroadcastNow'], ['DemoQueued', 'ShouldBroadcast'], $event));
file_put_contents($app.'/routes/web.php', $routes);
$env = <<<'ENV'
APP_NAME=BridgeDemo
APP_ENV=local
APP_KEY=base64:BRIDGE_KEY_PLACEHOLDER
APP_DEBUG=false
APP_URL=http://127.0.0.1:18092
LOG_CHANNEL=single
DB_CONNECTION=sqlite
SESSION_DRIVER=file
QUEUE_CONNECTION=database
CACHE_STORE=file
BROADCAST_CONNECTION=socketio
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=16389
REDIS_DB=0
SOCKET_BRIDGE_PREFIX=socket-bridge:e2e:fixture
SOCKET_BRIDGE_SECRET=BRIDGE_SECRET_PLACEHOLDER
SOCKET_BRIDGE_HOST=127.0.0.1
SOCKET_BRIDGE_PORT=16092
SOCKET_BRIDGE_URL=http://127.0.0.1:16092
SOCKET_BRIDGE_LARAVEL_URL=http://127.0.0.1:18092
SOCKET_BRIDGE_ORIGINS=http://127.0.0.1:18092
ENV;
$env = str_replace(['BRIDGE_KEY_PLACEHOLDER', 'BRIDGE_SECRET_PLACEHOLDER'], [base64_encode(random_bytes(32)), bin2hex(random_bytes(32))], $env);
$env = str_replace(['18092', '16092', 'socket-bridge:e2e:fixture'], [
    getenv('SOCKET_BRIDGE_E2E_HTTP_PORT') ?: '18092',
    getenv('SOCKET_BRIDGE_E2E_WS_PORT') ?: '16092',
    getenv('SOCKET_BRIDGE_E2E_PREFIX') ?: 'socket-bridge:e2e:fixture',
], $env);
file_put_contents($app.'/.env', $env."\n");
@touch($app.'/database/database.sqlite');
echo "Prepared disposable Laravel fixture; run migrations, then scripts/seed-e2e.php.\n";
