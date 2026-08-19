import type { MediaType } from '../libraries/mediaItemFields'

/** One row shared by every report — mirrors ReportsService::itemSummary(). */
export interface ReportItem {
  id: number
  title: string
  ean: string
  library_id: number
  library_name: string
  media_type: MediaType
  price: number | string | null
  currency: string | null
  created_at: string | null
}

export interface DuplicateGroup {
  ean: string
  media_type: MediaType
  items: ReportItem[]
}

export interface DataQualityRow extends ReportItem {
  missing_fields: string[]
}

export interface TopListRow extends ReportItem {
  value: number | string
}

export interface TopLists {
  most_expensive: TopListRow[]
  cheapest: TopListRow[]
  most_pages: TopListRow[]
  longest_cd_runtime: TopListRow[]
  shortest_cd_runtime: TopListRow[]
  longest_dvd_runtime: TopListRow[]
  shortest_dvd_runtime: TopListRow[]
  highest_disc_count: TopListRow[]
}

export interface CaptureSourceRow extends ReportItem {
  capture_method: 'scan' | 'manual' | null
  metadata_provider: string | null
  captured_by: string | null
}

export interface CaptureSourceResponse {
  items: CaptureSourceRow[]
  by_capture_method: Record<string, number>
  by_metadata_provider: Record<string, number>
}

/** GET /reports/sharing (GitHub issue #74, moved here from /statistics/sharing by GitHub issue #103) — mirrors ReportsService::sharingFor(). Only includes libraries the requesting user can manage (owner/admin), same restriction LibraryController::show() already applies to its own `shares` field. Rows are libraries, not media items, unlike every other report above — see reportTypes.ts's own docblock on REPORTS for why it's here regardless. */
export interface SharingRow {
  library_id: number
  library_name: string
  media_type: MediaType
  is_shared: boolean
  share_count: number
  shares: { scope: 'guest' | 'all_users' | 'user'; access_level: 'read' | 'write'; user_name: string | null }[]
}

/** GET /reports/user-activity (GitHub issue #74, moved here from /statistics/user-activity by GitHub issue #103) — mirrors ReportsService::userActivityFor(). `user_id`/`user_name` are null for items captured before this feature existed (no captured_by_user_id stored). Rows are users, not media items — see SharingRow's docblock. */
export interface UserActivityRow {
  user_id: number | null
  user_name: string | null
  item_count: number
  last_captured_at: string | null
}

/**
 * Every available report (GitHub issue #74, list/detail split GitHub issue
 * #101) — the single source of truth for both ReportsPage's overview list
 * and ReportDetailPage's routing/fetch, so the two can't drift out of sync.
 * `key` doubles as the GET /reports/<key> path segment and the `/reports/:key`
 * frontend route param.
 *
 * `sharing`/`user-activity` (GitHub issue #103) moved here from
 * StatisticsPage.tsx: both are tables (of libraries, of users) rather than
 * a chart/aggregate, which is what actually distinguishes an Auswertung
 * from a Statistik — not, as originally reasoned, whether each row is a
 * media item specifically. See ReportsService's own docblock for the full
 * history.
 */
export const REPORTS = [
  { key: 'duplicates', titleKey: 'reports.duplicates.title', hintKey: 'reports.duplicates.hint' },
  { key: 'data-quality', titleKey: 'reports.dataQuality.title', hintKey: 'reports.dataQuality.hint' },
  { key: 'recent-additions', titleKey: 'reports.recentAdditions.title', hintKey: 'reports.recentAdditions.hint' },
  { key: 'top-lists', titleKey: 'reports.topLists.title', hintKey: 'reports.topLists.hint' },
  { key: 'capture-source', titleKey: 'reports.captureSource.title', hintKey: 'reports.captureSource.hint' },
  { key: 'sharing', titleKey: 'reports.sharing.title', hintKey: 'reports.sharing.hint' },
  { key: 'user-activity', titleKey: 'reports.userActivity.title', hintKey: 'reports.userActivity.hint' },
] as const

export type ReportKey = (typeof REPORTS)[number]['key']
