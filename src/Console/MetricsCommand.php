<?php

namespace SocketBridge\Console;

use Illuminate\Console\Command;
use SocketBridge\Operations\PrometheusExporter;
use Throwable;

final class MetricsCommand extends Command
{
    protected $signature = 'socket-bridge:metrics';

    protected $description = 'Export Socket Bridge metrics in Prometheus text format';

    public function handle(PrometheusExporter $exporter): int
    {
        try {
            $this->output->write($exporter->render());

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Metrics unavailable. Check Redis, the database and package migrations.');

            return self::FAILURE;
        }
    }
}
