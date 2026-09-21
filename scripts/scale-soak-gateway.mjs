import { pathToFileURL } from "node:url";
import { resolve } from "node:path";
const directory =
  process.env.SOCKET_BRIDGE_SCALE_SNAPSHOT ??
  resolve(import.meta.dirname, "../gateway/dist");
const app = (await import(pathToFileURL(resolve(directory, "app.js")).href))
  .default;
const configs = (
  await import(pathToFileURL(resolve(directory, "config.js")).href)
).default;
const gateway = await app.createGateway(configs.loadConfig());
process.send?.({ type: "ready", port: gateway.address.port });
const timer = setInterval(() => {
  global.gc?.();
  process.send?.({
    type: "sample",
    at: Date.now(),
    ...gateway.runtime.snapshot(),
  });
}, 10000);
let stopping = false;
async function stop() {
  if (stopping) return;
  stopping = true;
  clearInterval(timer);
  await gateway.close();
  process.disconnect?.();
}
process.on("message", async (message) => {
  if (message.type === "close") await stop();
  if (message.type === "transport-fault") {
    for (const socket of gateway.runtime.io.sockets.sockets.values())
      socket.conn.close();
  }
});
process.on("SIGTERM", () => void stop());
