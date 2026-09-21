<?php

/** Disposable PostgreSQL fixture for the multi-process load harness. */
declare(strict_types=1);
$root = dirname(__DIR__);
$app = getenv('SOCKET_BRIDGE_E2E_APP') ?: $root.'/.test-results/scale-app';
if (! str_starts_with($app, $root.'/.test-results/') || str_contains($app, '/../')) {
    throw new RuntimeException('Scale fixture must be inside .test-results.');
}
if (! is_file($app.'/artisan')) {
    throw new RuntimeException('Run setup-e2e.php with SOCKET_BRIDGE_E2E_APP=.test-results/scale-app first.');
}
$env = file_get_contents($app.'/.env');
$changes = ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => getenv('SOCKET_BRIDGE_SCALE_PG_PORT') ?: '17432', 'DB_DATABASE' => 'bridge', 'DB_USERNAME' => 'bridge', 'DB_PASSWORD' => 'bridge-scale-test', 'REDIS_PORT' => getenv('SOCKET_BRIDGE_SCALE_REDIS_PORT') ?: '17389', 'SOCKET_BRIDGE_PREFIX' => 'socket-bridge:scale:'.(getenv('SOCKET_BRIDGE_SCALE_RUN') ?: 'default')];
foreach ($changes as $key => $value) {
    $line = $key.'='.$value;
    $env = preg_match('/^'.preg_quote($key, '/').'=/m', $env) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env) : $env."\n".$line;
}
file_put_contents($app.'/.env', $env."\n");
// Durable broadcast follows the same DB commit as the mutation and receipt.
$handler = <<<'PHP'
<?php
namespace App\BridgeDemo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use SocketBridge\Commands\CommandContext;
use SocketBridge\Contracts\CommandHandler;
use SocketBridge\Facades\Socket;
final class ScaleMutation implements CommandHandler {
 public function handle(array $payload, CommandContext $context): array {
  $data=Validator::make($payload,['operation_id'=>'required|uuid','sent_at'=>'required|integer'])->validate();
  $id=DB::table('scale_mutations')->insertGetId(['operation_id'=>$data['operation_id'],'user_id'=>$context->userId,'created_at'=>now()]);
  Socket::durable()->toRoom('private-scale')->emit('scale.mutated',['id'=>$id,'operation_id'=>$data['operation_id'],'sent_at'=>$data['sent_at']]);
  return ['id'=>$id,'operation_id'=>$data['operation_id']];
 }
}
PHP;
file_put_contents($app.'/app/BridgeDemo/ScaleMutation.php', $handler);
$provider = file_get_contents($app.'/app/Providers/AppServiceProvider.php');
$provider = str_replace("        Broadcast::channel('demo.{userId}'", "        Broadcast::channel('scale', fn (\$user) => true);\n        \$commands->register('scale.mutate', \\App\\BridgeDemo\\ScaleMutation::class);\n        Broadcast::channel('demo.{userId}'", $provider);
file_put_contents($app.'/app/Providers/AppServiceProvider.php', $provider);
// Fixture-only DB inspection, never included in a consuming application.
$routes = <<<'PHP'
Route::middleware('auth')->get('/scale/state', function () {
 return ['mutations'=>DB::table('scale_mutations')->count(), 'distinct_operations'=>DB::table('scale_mutations')->distinct()->count('operation_id'), 'receipts'=>DB::table('socket_bridge_command_receipts')->count(), 'outbox_pending'=>DB::table('socket_bridge_outbox')->whereNull('published_at')->count(), 'outbox_published'=>DB::table('socket_bridge_outbox')->whereNotNull('published_at')->count()];
});
PHP;
file_put_contents($app.'/routes/web.php', file_get_contents($app.'/routes/web.php')."\n".$routes."\n");
