<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/runtime/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$version = $manifest['package_version'];
foreach (['gateway'] as $component) {
    $metadata = json_decode(file_get_contents($root.'/'.$component.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($metadata['version'] !== $version) {
        throw new RuntimeException('Mismatched '.$component.' release version.');
    }
}
$node = getenv('SOCKET_BRIDGE_TEST_NODE_BINARY') ?: 'node';
$runtimeVersion = new Process([$node, $root.'/runtime/gateway.cjs', '--version']);
$runtimeVersion->mustRun();
if (trim($runtimeVersion->getOutput()) !== 'socket-bridge-gateway '.$version.' protocol/'.$manifest['protocol']) {
    throw new RuntimeException('Bundled gateway version does not match the release manifest.');
}
$directory = $root.'/.test-results/release';
@mkdir($directory, 0755, true);
$process = new Process([getenv('COMPOSER_BINARY') ?: 'composer', 'archive', '--format=zip', '--dir='.$directory, '--file=laravel-socket-bridge-'.$version], $root, ['COMPOSER_ROOT_VERSION' => $version]);
$process->setTimeout(120)->mustRun(static fn ($type, $buffer) => print $buffer);
$archive = $directory.'/laravel-socket-bridge-'.$version.'.zip';
$zip = new ZipArchive;
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Release archive could not be opened.');
}
foreach (['composer.json', 'runtime/gateway.cjs', 'runtime/probe.cjs', 'runtime/manifest.json', 'runtime/THIRD_PARTY_LICENSES.txt', 'src/SocketBridgeServiceProvider.php', 'docker/Dockerfile', 'stubs/command-handler.php.stub', 'stubs/deploy/supervisor.conf', 'stubs/deploy/socket-bridge-commands@.service', 'stubs/deploy/nginx.conf', 'stubs/deploy/Caddyfile'] as $required) {
    if ($zip->locateName($required) === false) {
        throw new RuntimeException('Missing release file: '.$required);
    }
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $path = $zip->getNameIndex($index);
    if (preg_match('~(^|/)(vendor|node_modules|\.git|\.test-results|\.phpunit.cache|\.env(?:\..*)?)($|/)~', $path)) {
        throw new RuntimeException('Private/development content in release: '.$path);
    }
}
$package = json_decode($zip->getFromName('composer.json'), true, flags: JSON_THROW_ON_ERROR);
$zip->close();
$package['version'] = $version;
$package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive, 'shasum' => sha1_file($archive)];
file_put_contents($directory.'/composer-repository.json', json_encode(['type' => 'package', 'package' => $package], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$checksums = [];
foreach ([$archive] as $artifact) {
    if (! is_file($artifact)) {
        continue;
    }
    $checksums[] = hash_file('sha256', $artifact).'  '.basename($artifact);
}
file_put_contents($directory.'/SHA256SUMS', implode("\n", $checksums)."\n");
echo 'Validated Composer release archive ('.filesize($archive).' bytes).'."\n";
