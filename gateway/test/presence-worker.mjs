import app from "../dist/app.js";
import configs from "../dist/config.js";

const gateway = await app.createGateway(configs.loadConfig());
process.send?.({ port: gateway.address.port });
