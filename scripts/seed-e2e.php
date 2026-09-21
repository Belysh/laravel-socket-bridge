<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

$appPath = getenv('SOCKET_BRIDGE_E2E_APP') ?: dirname(__DIR__).'/.test-results/laravel-app';
if (! str_starts_with($appPath, dirname(__DIR__).'/.test-results/') || str_contains($appPath, '/../')) {
    throw new RuntimeException('E2E fixtures must be inside this package\'s .test-results directory.');
}
require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Fixture environment only.');
}
foreach ([1, 2] as $id) {
    User::query()->updateOrCreate(['id' => $id], ['name' => 'Demo '.$id, 'email' => 'demo'.$id.'@example.test', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
}
if (! Schema::hasTable('demo_notes')) {
    Schema::create('demo_notes', function ($table) {
        $table->id();
        $table->string('user_id');
        $table->string('text');
    });
}
echo "Demo users and notes table ready.\n";
