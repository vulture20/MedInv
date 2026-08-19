/**
 * One clickable, sortable `<th>` in a table (GitHub issue #77, originally
 * LibraryDetailPage-only; extracted for GitHub issue #100 so SearchPage's
 * results table can look/behave identically) — a plain `<button>` filling
 * the header cell so the sort toggle stays keyboard/screen-reader operable,
 * same reasoning as media-item-table__title-button. `aria-sort` reflects the
 * *current* state (not just "this column is sortable") per the WAI-ARIA
 * table sorting pattern, so assistive tech announces which column and
 * direction is active without relying on the visual ▲/▼ glyph alone.
 *
 * Deliberately agnostic to *how* sorting is actually performed — the caller
 * decides (LibraryDetailPage re-fetches server-side sorted by `column`,
 * SearchPage sorts its already-fetched results client-side), this component
 * only renders the header and reports which column was clicked.
 */
export function SortableHeader({
  column,
  label,
  sortBy,
  sortDir,
  onSort,
}: {
  column: string
  label: string
  sortBy: string | null
  sortDir: 'asc' | 'desc'
  onSort: (column: string) => void
}) {
  const active = sortBy === column
  return (
    <th aria-sort={active ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}>
      <button type="button" className="media-item-table__sort-button" onClick={() => onSort(column)}>
        {label}
        {active && <span aria-hidden="true"> {sortDir === 'asc' ? '▲' : '▼'}</span>}
      </button>
    </th>
  )
}
