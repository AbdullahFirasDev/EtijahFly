FROM node:24-bookworm-slim AS build
WORKDIR /repo
COPY package.json package-lock.json ./
COPY packages/api-contracts/package.json packages/api-contracts/
COPY apps/partner-portal/package.json apps/partner-portal/
COPY apps/admin-portal/package.json apps/admin-portal/
RUN npm ci --ignore-scripts
COPY packages/api-contracts packages/api-contracts
COPY apps/admin-portal apps/admin-portal
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build -w admin-portal

FROM node:24-bookworm-slim AS runtime
ENV NODE_ENV=production HOSTNAME=0.0.0.0 PORT=3001 NEXT_TELEMETRY_DISABLED=1
WORKDIR /app
COPY --from=build /repo/apps/admin-portal/.next/standalone ./
COPY --from=build /repo/apps/admin-portal/.next/static ./apps/admin-portal/.next/static
USER node
EXPOSE 3001
# API_BASE_URL=http://api:8080/api/v1 must point at the internal API address.
CMD ["node", "apps/admin-portal/server.js"]
