const LOGIN_POSTER_BASE = 'https://web.ccpgamescdn.com/aws/eveonline/images/FracturedSkies-bg.jpg'

export const LOGIN_VIDEO_SRC = 'https://web.ccpgamescdn.com/aws/eveonline/videos/FracturedSkies-WH.webm'
export const LOGIN_POSTER_SRC = `${LOGIN_POSTER_BASE}?w=1700&q=75&fm=webp`
export const LOGIN_POSTER_SRCSET = [700, 1100, 1500, 1920, 2400].map((w) => `${LOGIN_POSTER_BASE}?w=${w}&q=75&fm=webp ${w}w`).join(', ')

export const LOGIN_BG_STORAGE_KEY = 'eve-industry-login-bg'

export function readLoginBg(): boolean {
  try {
    return localStorage.getItem(LOGIN_BG_STORAGE_KEY) === 'on'
  } catch {
    return false
  }
}

export function writeLoginBg(on: boolean): void {
  try {
    if (on) localStorage.setItem(LOGIN_BG_STORAGE_KEY, 'on')
    else localStorage.removeItem(LOGIN_BG_STORAGE_KEY)
  } catch {
  }
}
