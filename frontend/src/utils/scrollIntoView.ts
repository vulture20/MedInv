/**
 * Shared "scroll a newly-updated panel into view" helper (GitHub issue
 * #122, extended by #172 and reused by #177) — pulled out of SearchPage.tsx
 * once CapturePage.tsx needed the exact same fix for its own results panel,
 * so the sticky-header interaction below only has to be understood, and
 * kept correct, in one place.
 *
 * `scrollIntoView()`'s default `block: 'start'` aligns a target's own top
 * edge with the viewport's top edge — which then sits directly *behind*
 * `.app-header` (index.css's `position: sticky; top: 0`, pinned while
 * scrolled) unless `scroll-margin-top` accounts for its height, especially
 * bad on a small screen where the scroll then looks like it did nothing at
 * all. The header's height is measured live via `getBoundingClientRect()`
 * rather than hardcoded, so this stays correct if the header's own height
 * ever changes (responsive layout, added content, ...).
 */
export function prefersReducedMotion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export function scrollPastStickyHeader(target: HTMLElement) {
  const header = document.querySelector('.app-header')
  const headerHeight = header instanceof HTMLElement ? header.getBoundingClientRect().height : 0
  target.style.scrollMarginTop = `${headerHeight + 8}px`
  target.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' })
}
