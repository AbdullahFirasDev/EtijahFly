import type { Branding } from '@etijahfly/api-contracts'

interface PublicBranding {
  tenant: { slug: string, name: string } | null
  branding: Branding | null
  platform: string
}

const HEX = /^#[0-9a-f]{6}$/i

/** White-label: applies the agency's name, logo and colours to the portal at runtime. */
export function useBranding() {
  const branding = useState<Branding | null>('branding', () => null)
  const tenantSlug = useState<string | null>('branding-slug', () => null)
  const config = useRuntimeConfig()

  function apply(value: Branding | null, slug?: string | null): void {
    branding.value = value
    if (slug !== undefined) tenantSlug.value = slug

    if (!import.meta.client) return
    const root = document.documentElement
    if (value && HEX.test(value.primary_color) && HEX.test(value.secondary_color)) {
      root.style.setProperty('--brand-primary', value.primary_color)
      root.style.setProperty('--brand-secondary', value.secondary_color)
      root.dataset.branded = 'true'
    }
    else {
      root.style.removeProperty('--brand-primary')
      root.style.removeProperty('--brand-secondary')
      delete root.dataset.branded
    }
  }

  /** Before login: resolve the agency from the portal host (custom domain) or ?agency=slug. */
  async function loadPublic(): Promise<void> {
    const route = useRoute()
    const slug = typeof route.query.agency === 'string' ? route.query.agency : undefined
    try {
      const res = await $fetch<PublicBranding>('/api/proxy/public/branding', {
        query: { host: window.location.host, slug },
      })
      apply(res.branding, res.tenant?.slug ?? null)
    }
    catch {
      apply(null, null)
    }
  }

  const displayName = computed(() => branding.value?.display_name ?? config.public.platformName)
  const logoUrl = computed(() => branding.value?.has_logo && tenantSlug.value
    ? `/api/proxy/public/tenants/${tenantSlug.value}/logo?v=${branding.value.logo_version}`
    : null)

  return { branding, displayName, logoUrl, apply, loadPublic, tenantSlug }
}
