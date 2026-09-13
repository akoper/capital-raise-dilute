# Build stage
FROM node:22-alpine AS build

WORKDIR /app

# Upgrade npm to the latest version (or a specific version like 12.0.2)
RUN npm install -g npm@latest

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build -- --configuration production

# Production Nginx stage
FROM nginx:alpine

COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 8080

CMD ["nginx", "-g", "daemon off;"]
