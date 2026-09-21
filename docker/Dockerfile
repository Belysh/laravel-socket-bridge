FROM node:24.21.0-bookworm-slim

ENV NODE_ENV=production SOCKET_BRIDGE_HOST=0.0.0.0 SOCKET_BRIDGE_PORT=6001
WORKDIR /app
COPY --chown=node:node runtime/gateway.cjs /app/gateway.cjs
COPY --chown=node:node runtime/probe.cjs /app/probe.cjs
COPY --chown=node:node runtime/THIRD_PARTY_LICENSES.txt /app/THIRD_PARTY_LICENSES.txt
USER node
EXPOSE 6001
STOPSIGNAL SIGTERM
CMD ["node", "/app/gateway.cjs"]
