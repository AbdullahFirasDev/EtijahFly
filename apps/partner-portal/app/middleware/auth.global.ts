const PUBLIC_ROUTES = ['/login', '/forgot-password', '/reset-password']

export default defineNuxtRouteMiddleware(async (to) => {
  const isPublic = PUBLIC_ROUTES.includes(to.path) || to.path.startsWith('/invite/')
  const { me, loaded, fetchMe, can } = useAuth()

  if (!loaded.value) await fetchMe()

  if (isPublic) {
    return me.value && to.path === '/login' ? navigateTo('/') : undefined
  }

  if (!me.value) {
    return navigateTo({ path: '/login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} })
  }

  const required = to.meta.permission as string | string[] | undefined
  if (required && !(Array.isArray(required) ? required.some(can) : can(required))) {
    return abortNavigation(createError({ statusCode: 403, statusMessage: 'Forbidden' }))
  }
})
