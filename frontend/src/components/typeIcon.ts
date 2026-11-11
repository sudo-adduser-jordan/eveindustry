import type { SyntheticEvent } from 'react'

const IMAGE_SERVER = 'https://images.evetech.net/types'


export const typeIconUrl = (typeId: number, size = 32) => `${IMAGE_SERVER}/${typeId}/icon?size=${size}`


export function iconFallback(e: SyntheticEvent<HTMLImageElement>) {
  const img = e.currentTarget
  if (img.src.includes('/icon?')) {
    img.src = img.src.replace('/icon?', '/bp?')
  } else {
    img.hidden = true
    img.style.display = 'none'
  }
}
