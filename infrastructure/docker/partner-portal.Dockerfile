FROM node:24-bookworm-slim AS build
WORKDIR /repo
COPY package.json package-lock.json ./
COPY packages/api-contracts/package.json packages/api-contracts/
COPY apps/partner-portal/package.json apps/partner-portal/
COPY apps/admin-portal/package.json apps/admin-portal/
RUN npm ci --ignore-scripts
COPY packages/api-contracts packages/api-contracts
COPY apps/partner-portal apps/partner-portal
RUN npm run build -w partner-portal

FROM node:24-bookworm-slim AS runtime
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3000
WORKDIR /app
COPY --from=build /repo/apps/partner-portal/.output ./.output
USER node
EXPOSE 3000
# NUXT_API_BASE=http://api:8080/api/v1 must point at the internal API address.
CMD ["node", ".output/server/index.mjs"]
