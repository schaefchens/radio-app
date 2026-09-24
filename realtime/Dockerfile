# The realtime node image. One image for both places it runs: the `realtime`
# service of the local compose stack and every Hetzner node (the snapshot
# carries it; infra/realtime/compose.node.yaml starts it). Build context is the
# repo root:  docker build -f realtime/Dockerfile -t arche-realtime:dev .
#
# No build step: Node 24 strips the TypeScript types itself. That only works
# outside node_modules, which is why the image keeps the repo layout —
# node_modules/@arche/shared is npm's workspace symlink to ../../shared, Node
# resolves it to its real path /app/shared/src, and strips it there.

FROM node:24-alpine AS deps
WORKDIR /app
# Every workspace manifest, because `npm ci` checks the lock file against all
# of them; only @arche/realtime's production dependencies are installed.
COPY package.json package-lock.json ./
COPY shared/package.json shared/package.json
COPY realtime/package.json realtime/package.json
COPY app/package.json app/package.json
RUN npm ci --omit=dev --workspace @arche/realtime --include-workspace-root=false --ignore-scripts --no-audit --no-fund

FROM node:24-alpine
ENV NODE_ENV=production
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY shared/package.json shared/package.json
COPY shared/src shared/src
COPY realtime/package.json realtime/package.json
COPY realtime/src realtime/src
# The app workspace link would dangle in this image; nothing imports it.
RUN rm -f node_modules/@arche/app
USER node
WORKDIR /app/realtime
EXPOSE 8787
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s \
  CMD wget -qO- http://127.0.0.1:${PORT:-8787}/health >/dev/null || exit 1
CMD ["node", "src/main.ts"]
