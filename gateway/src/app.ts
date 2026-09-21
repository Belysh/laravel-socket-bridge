import 'reflect-metadata';
import { readFileSync } from 'node:fs';
import { timingSafeEqual } from 'node:crypto';
import { Controller, Get, Header, Inject, Module, NotFoundException, Req, ServiceUnavailableException, UnauthorizedException } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { ExpressAdapter } from '@nestjs/platform-express';
import { Config } from './config';
import { GatewayRuntime } from './runtime';
@Controller('health')
class HealthController {
  constructor(@Inject(GatewayRuntime) private readonly runtime: GatewayRuntime) {}
  @Get('live') live(): object { return { status: 'ok', service: 'socket-bridge', protocol: 1 }; }
  @Get('ready') async ready(): Promise<object> {
    if (!await this.runtime.ready()) throw new ServiceUnavailableException('Realtime dependencies unavailable');
    return { status: 'ready', protocol: 1 };
  }
}
@Controller('metrics')
class MetricsController {
  constructor(@Inject(GatewayRuntime) private readonly runtime: GatewayRuntime) {}
  @Get()
  @Header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
  @Header('Cache-Control', 'no-store')
  metrics(@Req() request: { headers: { authorization?: string } }): string {
    const secret = this.runtime.config.metricsToken;
    if (!secret) throw new NotFoundException();
    const actual = Buffer.from(request.headers.authorization ?? '');
    const expected = Buffer.from(`Bearer ${secret}`);
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) throw new UnauthorizedException();
    return this.runtime.prometheus();
  }
}
export async function createGateway(config: Config) {
  const runtime = new GatewayRuntime(config);
  @Module({ controllers: [HealthController, MetricsController], providers: [{ provide: GatewayRuntime, useValue: runtime }] })
  class AppModule {}
  const httpsOptions = config.tlsCert && config.tlsKey ? { cert: readFileSync(config.tlsCert), key: readFileSync(config.tlsKey) } : undefined;
  const app = await NestFactory.create(AppModule, new ExpressAdapter(), { logger: false, abortOnError: false, ...(httpsOptions ? { httpsOptions } : {}) });
  try {
    await runtime.attach(app.getHttpServer());
    await app.listen(config.port, config.host);
    return {
      app, runtime,
      address: app.getHttpServer().address(),
      close: async () => { await runtime.close(); await app.close(); },
    };
  } catch (error) { await runtime.close(); await app.close(); throw error; }
}
