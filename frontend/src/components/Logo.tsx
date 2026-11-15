export function Logo({ size = 28 }: { size?: number }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width={size} height={size} aria-hidden="true">
      <rect width="64" height="64" rx="14" fill="var(--logo-bg, var(--panel2))" />
      <rect x="2" y="2" width="60" height="60" rx="12" fill="none" stroke="var(--logo-ring, var(--accent))" strokeOpacity="0.45" strokeWidth="3" />
      <polygon points="46,32 39,44.1 25,44.1 18,32 25,19.9 39,19.9" fill="none" stroke="var(--logo-frame, var(--accent))" strokeWidth="4" strokeLinejoin="round" />
      <circle cx="32" cy="32" r="5" fill="var(--logo-dot, var(--accent2))" />
    </svg>
  )
}
