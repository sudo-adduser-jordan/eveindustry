import { QueryClient } from '@tanstack/react-query'
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { App } from './App'
import {
  PERSIST_BUSTER, PERSIST_MAX_AGE, persister, refreshMeOnReconnect, shouldDehydrate, watchSignedInUser,
} from './api/persistence'
import './styles/tokens.css'
import './styles/app.css'
import './styles/theme.css'
import './styles/themes/industrialist.css'
import './styles/themes/eve-online.css'
import './styles/themes/carbon.css'
import './styles/themes/amarr.css'
import './styles/themes/caldari.css'
import './styles/themes/gallente.css'
import './styles/themes/minmatar.css'
import './styles/themes/angel-cartel.css'
import './styles/themes/blood-raiders.css'
import './styles/themes/guristas.css'
import './styles/themes/sansha.css'
import './styles/themes/serpentis.css'
import './styles/themes/dark.css'
import './styles/themes/light.css'
import { captureInstallPrompt } from './pwa/install'
import { registerServiceWorker } from './pwa/register'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      
      
      staleTime: 30_000,
      refetchOnWindowFocus: true,
      refetchOnReconnect: true,
      
      networkMode: 'offlineFirst',
      
      gcTime: PERSIST_MAX_AGE,
    },
  },
})
watchSignedInUser(queryClient)
refreshMeOnReconnect(queryClient)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <PersistQueryClientProvider
      client={queryClient}
      persistOptions={{ persister, maxAge: PERSIST_MAX_AGE, buster: PERSIST_BUSTER, dehydrateOptions: { shouldDehydrateQuery: shouldDehydrate } }}
    >
      <BrowserRouter basename="/app">
        <App />
      </BrowserRouter>
    </PersistQueryClientProvider>
  </StrictMode>,
)

captureInstallPrompt()
registerServiceWorker()
