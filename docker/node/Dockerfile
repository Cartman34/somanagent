FROM node:20-alpine

# Useful tools
RUN apk add --no-cache git curl

WORKDIR /app

# Install dependencies at build time so the dev server starts immediately.
# The docker-compose volume mount (./frontend:/app) will overlay /app at
# runtime; the companion anonymous volume (/app/node_modules) preserves the
# node_modules installed here, preventing the host directory from hiding them.
COPY frontend/package*.json ./
RUN npm install

# Expose Vite dev server port
EXPOSE 5173
