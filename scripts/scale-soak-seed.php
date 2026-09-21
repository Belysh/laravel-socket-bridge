<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

$appPath = getenv('SOCKET_BRIDGE_E2E_APP') ?: dirname(__DIR__).'/.test-results/scale-app';
if (! str_starts_with($appPath, dirname(__DIR__).'/.test-results/') || str_contains($appPath, '/../')) {
    throw new RuntimeException('Scale fixture must be inside .test-results.');
}
require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! Schema::hasTable('scale_mutations')) {
    Schema::create('scale_mutations', function ($table) {
        $table->id();
        $table->uuid('operation_id');
        $table->string('user_id');
        $table->timestamp('created_at');
    });
}
foreach ([1, 2] as $id) {
    User::query()->updateOrCreate(['id' => $id], ['name' => 'Scale '.$id, 'email' => 'scale'.$id.'@example.test', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
}
echo "Scale database ready.\n";
