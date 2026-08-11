import logoUrl from '../assets/logo.svg'

interface LogoProps {
  /** Pixel size of the square logo mark. */
  size?: number
  /** Hide the "MedInv" wordmark and render only the mark (e.g. in a tight header). */
  markOnly?: boolean
}

/** The MedInv logo + wordmark, shared by the header (briefing 11.2) and the login screen. */
export function Logo({ size = 32, markOnly = false }: LogoProps) {
  return (
    <span className="logo">
      <img src={logoUrl} alt="MedInv" width={size} height={size} className="logo__mark" />
      {!markOnly && <span className="logo__wordmark">MedInv</span>}
    </span>
  )
}
