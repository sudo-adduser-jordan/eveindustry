
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'

declare const self: ServiceWorkerGlobalScope


cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)


registerRoute(
  new NavigationRoute(async ({ request }) => {
    try {
      return await fetch(request)
    } catch {
      return (await matchPrecache('index.html')) ?? Response.error()
    }
  }),
)


self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') void self.skipWaiting()
})
