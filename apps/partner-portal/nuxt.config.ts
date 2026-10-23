// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2026-10-01',

  modules: ['@nuxt/ui', '@nuxtjs/i18n', '@nuxt/eslint'],

  // Authenticated application: rendered in the browser; the Nitro server acts as a
  // backend-for-frontend (holds the API token in an httpOnly cookie and proxies /api/v1).
  ssr: false,

  devtools: { enabled: false },

  css: ['~/assets/css/main.css'],

  app: {
    head: {
      htmlAttrs: { lang: 'ar', dir: 'rtl' },
      title: 'EtijahFly',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'robots', content: 'noindex' },
      ],
    },
  },

  runtimeConfig: {
    // Server-only. Base URL of the Laravel API, e.g. http://127.0.0.1:8000/api/v1
    apiBase: 'http://127.0.0.1:8000/api/v1',
    // Cookie flags: secure must be true behind HTTPS in production.
    cookieSecure: process.env.NODE_ENV === 'production',
    // Server-only. Shared with the API (PORTAL_PROXY_SECRET) so it trusts the forwarded user IP
    // when it runs on a separate host. Set via NUXT_PORTAL_PROXY_SECRET.
    portalProxySecret: '',
    public: {
      platformName: 'EtijahFly',
    },
  },

  i18n: {
    defaultLocale: 'ar',
    strategy: 'no_prefix',
    locales: [
      { code: 'ar', language: 'ar-IQ', dir: 'rtl', name: 'العربية', file: 'ar.json' },
      { code: 'en', language: 'en-US', dir: 'ltr', name: 'English', file: 'en.json' },
    ],
    detectBrowserLanguage: { useCookie: true, cookieKey: 'ef_locale', fallbackLocale: 'ar', redirectOn: 'root' },
  },

  icon: {
    serverBundle: 'local',
    clientBundle: { scan: true },
  },

  fonts: {
    families: [
      { name: 'IBM Plex Sans Arabic', provider: 'google', weights: [400, 500, 600, 700] },
      { name: 'Reem Kufi', provider: 'google', weights: [500, 600, 700] },
    ],
  },

  eslint: {
    config: { stylistic: false },
  },

  nitro: {
    routeRules: {
      '/**': {
        headers: {
          'X-Frame-Options': 'DENY',
          'X-Content-Type-Options': 'nosniff',
          'Referrer-Policy': 'strict-origin-when-cross-origin',
          'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
        },
      },
    },
  },

  typescript: {
    strict: true,
  },
})
