FROM node:24-alpine
WORKDIR /app
COPY server.mjs probes.mjs ./
USER node
EXPOSE 8090
CMD ["node","server.mjs"]
