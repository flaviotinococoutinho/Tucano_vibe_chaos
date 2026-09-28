# Web on Node 24 to build, nginx to serve. Unlike the other Node services, this one is not
# run directly: Vite bundles the TypeScript and JSX into static files, and only those files
# make it into the final image. The build context is the repository root, like every service
# image.
FROM node:24-alpine AS build

WORKDIR /srv/services/web

# Dependencies first: this layer is rebuilt only when the manifest or the lock file change.
COPY services/web/package.json services/web/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY services/web/index.html services/web/tsconfig.json services/web/vite.config.ts ./
COPY services/web/public ./public
COPY services/web/src ./src
RUN npm run build

FROM nginx:stable-alpine

# The security headers live outside conf.d/ on purpose: the base image glob-includes every
# *.conf under conf.d/ straight into the http block, and this one is a snippet meant only for
# the `include` inside nginx.conf's own locations, not a server block of its own.
COPY services/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY services/web/nginx-security-headers.conf /etc/nginx/snippets/nginx-security-headers.conf
COPY --from=build /srv/services/web/dist /usr/share/nginx/html

EXPOSE 80
