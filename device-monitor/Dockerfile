FROM node:24-alpine
WORKDIR /app
COPY monitor.mjs ./
USER node
EXPOSE 8091
CMD ["node","monitor.mjs"]
