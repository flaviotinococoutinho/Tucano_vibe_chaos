# BFF on Node 24. Node runs the TypeScript sources directly (type stripping), so there
# is no build stage. The build context is the repository root, like every service image.
FROM node:24-alpine

WORKDIR /srv/services/bff

# Dependencies first: this layer is rebuilt only when the manifest or the lock file change.
COPY services/bff/package.json services/bff/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force

COPY services/bff/src ./src

USER node
EXPOSE 3000

# Node runs as PID 1 and handles SIGTERM itself, so `docker stop` closes the server gracefully.
CMD ["node", "src/server.ts"]
