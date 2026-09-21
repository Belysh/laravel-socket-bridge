<?php

declare(strict_types=1);

namespace SocketBridge\Runtime;

use Dotenv\Dotenv;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Read-only discovery. Project files and running Docker metadata are evidence, not instructions. */
final class ProfileDetector
{
    public function __construct(private readonly string $project, private readonly ?string $home = null, private readonly ?bool $container = null) {}

    public function detect(bool $inspectDocker = true, ?string $preferred = null): array
    {
        $home = $this->home ?? (getenv('HOME') ?: '');
        $inside = $this->container ?? ((bool) getenv('LARAVEL_SAIL') || is_file('/.dockerenv'));
        $result = ['profile' => 'php', 'evidence' => [], 'inside_container' => $inside, 'site_url' => null, 'ca_cert' => null, 'tls_cert' => null, 'tls_key' => null,
            'compose' => null, 'services' => [], 'laravel_service' => null, 'laravel_port' => 80, 'browser_port' => 80, 'redis_service' => null, 'network' => null];
        $environment = is_file($this->project.'/.env') ? Dotenv::parse((string) file_get_contents($this->project.'/.env')) : [];
        if ($preferred === 'php') {
            return $result;
        }
        foreach (in_array($preferred, ['herd', 'valet'], true) ? [] : ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $file) {
            if (! is_file($this->project.'/'.$file)) {
                continue;
            }
            try {
                $compose = Yaml::parseFile($this->project.'/'.$file);
            } catch (\Throwable) {
                continue;
            }
            if (! is_array($compose['services'] ?? null)) {
                continue;
            }
            foreach ($compose['services'] as $service => $settings) {
                if (! is_array($settings)) {
                    continue;
                }
                $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES);
                if (str_contains($encoded, 'laravel/sail') || str_contains((string) ($settings['image'] ?? ''), 'sail-') || str_contains($encoded, 'LARAVEL_SAIL')) {
                    $result['profile'] = 'sail';
                    $result['evidence'][] = $file.':services.'.$service;
                    $result['compose'] = $this->project.'/'.$file;
                    $result['services'] = array_keys($compose['services']);
                    $result['laravel_service'] = (string) $service;
                    foreach ($settings['ports'] ?? [] as $port) {
                        $target = is_array($port) ? ($port['target'] ?? null) : preg_replace('/\/tcp$/', '', substr((string) $port, strrpos((string) $port, ':') + 1));
                        if (is_numeric($target) && (int) $target !== 5173) {
                            $result['laravel_port'] = (int) $target;
                            $mapping = is_array($port) ? ($port['published'] ?? '80') : $this->interpolate((string) $port, $environment);
                            $parts = explode(':', (string) $mapping);
                            $published = is_array($port) ? $mapping : ($parts[count($parts) - 2] ?? '80');
                            $result['browser_port'] = is_numeric($published) ? (int) $published : 80;
                            break;
                        }
                    }
                    foreach ($compose['services'] as $name => $candidate) {
                        if (preg_match('#(?:^|/)redis(?::|$)#', (string) ($candidate['image'] ?? ''))) {
                            $result['redis_service'] = (string) $name;
                            break;
                        }
                    }
                    $networks = $settings['networks'] ?? ['default'];
                    $networks = array_is_list($networks) ? $networks : array_keys($networks);
                    if ($result['redis_service']) {
                        $redisNetworks = $compose['services'][$result['redis_service']]['networks'] ?? ['default'];
                        $redisNetworks = array_is_list($redisNetworks) ? $redisNetworks : array_keys($redisNetworks);
                        $networks = array_values(array_intersect($networks, $redisNetworks));
                    }
                    foreach ($networks as $network) {
                        $definition = $compose['networks'][$network] ?? [];
                        $name = $definition['name'] ?? null;
                        if (is_string($name)) {
                            $name = $this->interpolate($name, $environment);
                        }
                        $projectName = $environment['COMPOSE_PROJECT_NAME'] ?? $compose['name'] ?? null;
                        if (! $name && is_string($projectName) && preg_match('/^[a-zA-Z0-9_.-]+$/D', $projectName)) {
                            $name = $projectName.'_'.$network;
                        }
                        if (is_string($name) && preg_match('/^[a-zA-Z0-9_.-]+$/D', $name)) {
                            $result['network'] = $name;
                            break;
                        }
                    }
                    if ($inspectDocker && $networks !== [] && ($observed = $this->runningNetwork($result['compose'], (string) $service, $result['network'])) !== null) {
                        $result['network'] = $observed;
                        $result['evidence'][] = 'running Docker service network';
                    }

                    return $result;
                }
            }
        }
        if ($preferred === 'sail') {
            return $result;
        }
        foreach (['herd' => $home.'/Library/Application Support/Herd/config/valet', 'valet' => $home.'/.config/valet'] as $profile => $directory) {
            if ($preferred !== null && $preferred !== $profile) {
                continue;
            }
            $configuration = is_file($directory.'/config.json') ? json_decode((string) file_get_contents($directory.'/config.json'), true) : [];
            $site = null;
            $secureRequested = false;
            foreach (glob($directory.'/Sites/*') ?: [] as $link) {
                if (is_link($link) && realpath($link) === realpath($this->project)) {
                    $site = basename($link);
                    $result['evidence'][] = $profile.' linked site';
                    break;
                }
            }
            foreach ($configuration['paths'] ?? [] as $parked) {
                if (realpath(dirname($this->project)) === realpath($parked)) {
                    $site ??= basename($this->project);
                    $result['evidence'][] = $profile.' parked project directory';
                }
            }
            if ($profile === 'herd' && is_file($this->project.'/herd.yml')) {
                $result['evidence'][] = 'herd.yml';
                try {
                    $herd = Yaml::parseFile($this->project.'/herd.yml');
                    $secureRequested = ($herd['secured'] ?? false) === true;
                    $site ??= is_string($herd['name'] ?? null) ? $herd['name'] : basename($this->project);
                } catch (\Throwable) {
                    $site ??= basename($this->project);
                }
            }
            if ($profile === 'valet' && is_file($this->project.'/.valetrc')) {
                $site ??= basename($this->project);
                $result['evidence'][] = '.valetrc';
            }
            if ($site === null) {
                continue;
            }
            $result['profile'] = $profile;
            $domain = $site.'.'.($configuration['tld'] ?? 'test');
            if (! preg_match('/^[a-zA-Z0-9.-]+$/D', $domain)) {
                return $result;
            }
            $certificate = $directory.'/Certificates/'.$domain.'.crt';
            $key = $directory.'/Certificates/'.$domain.'.key';
            $secure = is_readable($certificate) && is_readable($key);
            $result['site_url'] = ($secure || $secureRequested ? 'https' : 'http').'://'.$domain;
            $result['tls_cert'] = $secure ? $certificate : null;
            $result['tls_key'] = $secure ? $key : null;
            foreach ([$directory.'/CA/LaravelValetCASelfSigned.pem', $directory.'/CA/HerderCASelfSigned.pem'] as $ca) {
                if (is_readable($ca)) {
                    $result['ca_cert'] = $ca;
                    break;
                }
            }

            return $result;
        }

        return $result;
    }

    private function interpolate(string $value, array $environment): string
    {
        return preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/', static fn ($match) => $environment[$match[1]] ?? $match[2] ?? '', $value);
    }

    private function runningNetwork(string $compose, string $service, ?string $preferred): ?string
    {
        $docker = (new ExecutableFinder)->find('docker');
        if ($docker === null) {
            return null;
        }
        try {
            $process = new Process([$docker, 'compose', '-f', $compose, 'ps', '-q', $service], $this->project);
            $process->setTimeout(3);
            $process->run();
            $id = trim($process->getOutput());
            if (! $process->isSuccessful() || ! preg_match('/^[a-f0-9]{12,64}$/D', $id)) {
                return null;
            }
            $inspect = new Process([$docker, 'inspect', '--format', '{{json .NetworkSettings.Networks}}', $id]);
            $inspect->setTimeout(3);
            $inspect->mustRun();
            $networks = json_decode($inspect->getOutput(), true);
            if (! is_array($networks)) {
                return null;
            }
            if ($preferred !== null && isset($networks[$preferred])) {
                return $preferred;
            }

            return count($networks) === 1 ? array_key_first($networks) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
