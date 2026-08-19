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

/**
 * Every available report (GitHub issue #74, list/detail split GitHub issue
 * #101) — the single source of truth for both ReportsPage's overview list
 * and ReportDetailPage's routing/fetch, so the two can't drift out of sync.
 * `key` doubles as the GET /reports/<key> path segment and the `/reports/:key`
 * frontend route param.
 */
export const REPORTS = [
  { key: 'duplicates', titleKey: 'reports.duplicates.title', hintKey: 'reports.duplicates.hint' },
  { key: 'data-quality', titleKey: 'reports.dataQuality.title', hintKey: 'reports.dataQuality.hint' },
  { key: 'recent-additions', titleKey: 'reports.recentAdditions.title', hintKey: 'reports.recentAdditions.hint' },
  { key: 'top-lists', titleKey: 'reports.topLists.title', hintKey: 'reports.topLists.hint' },
  { key: 'capture-source', titleKey: 'reports.captureSource.title', hintKey: 'reports.captureSource.hint' },
] as const

export type ReportKey = (typeof REPORTS)[number]['key']
