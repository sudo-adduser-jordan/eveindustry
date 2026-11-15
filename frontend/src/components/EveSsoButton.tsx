import eveSsoLoginBlack from '../assets/eve-sso-login-black-large.png'
import eveSsoLoginWhite from '../assets/eve-sso-login-white-large.png'

export function EveSsoButton() {
  return (
    <a className="eve-sso-button" href="/auth/eve/scopes">
      <img className="eve-sso-white" src={eveSsoLoginWhite} alt="" aria-hidden="true" width={270} height={45} />
      <img className="eve-sso-black" src={eveSsoLoginBlack} alt="" aria-hidden="true" width={270} height={45} />
      <span className="visually-hidden">Log in with EVE Online</span>
    </a>
  )
}
