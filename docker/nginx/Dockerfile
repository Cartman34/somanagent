FROM nginx:1.25-alpine

RUN apk add --no-cache bash

COPY default.conf /etc/nginx/conf.d/default.conf

RUN rm /etc/nginx/conf.d/default.conf.bak 2>/dev/null || true
