FROM node:22-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev --ignore-scripts
COPY *.mjs ./
RUN mkdir -p /data && chown node:node /data
USER node
ENV NODE_ENV=production WAG_BAILEYS_HOST=0.0.0.0 WAG_BAILEYS_PORT=3318 \
    WAG_BAILEYS_SESSION_ROOT=/data/sessions WAG_BAILEYS_STATE_ROOT=/data/runner-state \
    WAG_BAILEYS_EVENT_ROOT=/data/runner-events WAG_BAILEYS_JOURNAL_ROOT=/data/whatsapp-messages
EXPOSE 3318
CMD ["node", "server.mjs"]
