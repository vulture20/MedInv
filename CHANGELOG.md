# Changelog

All notable changes to MedInv are documented in this file. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows the two-component `vMAJOR.MINOR` scheme used by
`backend/config/medinv.php` and this project's Docker tags, not semver.

## [Unreleased]

### Security
- Bump `league/commonmark` (a transitive dependency of `laravel/framework`) from 2.9.2 to 2.10.0, addressing GHSA-8rr7-cvq3-gmfh, a denial-of-service in its Attributes extension — never reachable in this app in practice, since that extension is never registered and no app code renders Markdown

## [0.8] - 2026-09-01

### Added
- Extract cast ("Darsteller") from JPC DVD/Blu-ray listings (#213)
- Extract book/CD/DVD-Blu-ray descriptions from JPC's "Weiterlesen" synopsis box (#214)
- Add a "keep current value" option to the metadata merge review, so a field a fresh lookup can't reproduce isn't silently overwritten or erased (#218)
- Let admins edit a media item's EAN, toggleable in settings, default off (#201, #202)
- Confirm before discarding unsaved media item edits (#200)
- Add a personal items-per-page setting (20/50/100/200, default 50) (#194)
- Add a numbered pagination strip with ellipsis and URL sync (#196)
- Add a config-test check for UpcMdbProvider (#161)
- Add duplicate-copy tracking ("Dubletten vorhanden") to media items (#208)
- Add a "Dubletten" checkbox next to Fuzzy-Suche in search (#209)
- Add a marketplace/country selector to the Amazon metadata plugin (#210)
- Add upcitemdb.com as a last-resort, name-only barcode fallback provider (#192)
- Let a pending scan result be redirected to a different library (#191)
- Allow rejecting an individual found metadata value during merge review (#189)

### Changed
- Relicense MedInv from MIT to AGPL-3.0-or-later
- `:latest` now tracks the newest release; rolling main-branch builds moved to `:nightly`
- Let a manual `workflow_dispatch` rebuild an older tag under today's tagging logic
- Log write failures (403/404/409) on media item edits (#203)
- Offer rejecting a merge field as a radio option, not a checkbox (#190)
- Prefer JPC's dedicated "Medium" display field over the `<title>` tag for `disc_count`/medium (#215)
- Bump JPC provider version to v1.1
- Wrap Fuzzy-Suche/Dubletten search filters in a bordered "Optionen" fieldset, side by side

### Fixed
- Fix items-per-page setting reverting to its default after leaving Settings
- Fix untranslated "config field is required" error on a plugin's Test button (#197)
- Translate more admin error messages: taken email/code (#198)
- Source the timezone dropdown from the backend, not the browser (#199)
- Split comma-separated genre/medium into individual values in search and statistics (#204)
- Strip a stray key/tonality annotation from `languages`, causing duplicate entries in search/statistics (#205)
- Sort the Erscheinungsjahr statistics distribution by count, not by year (#206)
- Normalize Amazon currency to a valid ISO code before it's ever returned (#212)
- Fix JPC extracting the wrong "Weiterlesen" block (and gluing paragraphs together with no space) for books/CDs (#216)
- Fix JPC's "Blu-ray Discs" collapsing to "Blu-ray Disc" instead of "Blu-ray" (#217)
- Recombine a flattened "Last, First" Amazon Actors bullet into proper "First Last" names (#219)
- Backfill the `capture.mergeKeepValue` translation key into all 15 bundled language packs (#220)

### Security
- Validate a metadata plugin's `select`-type config values (e.g. Amazon's `marketplace`) server-side against their declared options, closing an SSRF where an unvalidated value became the outbound scrape request's host (#221)
- Purge a deleted user's other active sessions (e.g. a second device/browser) instead of leaving them until Laravel's own session garbage collection happened to sweep them (#222)

## [0.7] - 2026-08-24

### Added
- Add Mistral as a fourth LLM-backed metadata source (#68)
- Add TMDB as a search-only DVD/Blu-ray metadata provider (#157), later enriched with cast/director/runtime from its detail endpoint (#165) and an EAN-support column in the plugin list (#158)
- Add a title-based second round to EAN metadata lookups for providers that can't search by code at all (#159), trying up to the top 3 disagreeing titles instead of giving up on any disagreement
- Add capture-without-EAN support with a `NoEAN-…` placeholder (#151), disabling "refresh metadata" for those items (#156) and defaulting `disc_count` instead of sending `NULL` (#155)
- Add config-test checks (a "Test" button against the real API) to HardcoverProvider (#162), DiscogsProvider (#163), GoogleBooksProvider (#164), and TMDB (#160)
- Add backup upload (restoring a backup downloaded from elsewhere), and raise PHP/nginx upload size limits that were silently capping it (#167, #168)
- Reuse an uploaded backup's original filename timestamp instead of the upload time (#169)
- Let a user change their own password (#174) and let admins set any user's password, with a styled password panel (#175)
- Add a last-login column to the admin user table (#181)
- Let libraries be excluded from statistics/reports, and from the dashboard, per user (#176, #179)
- Include per-user library preferences in real backups (#180)
- Split director/cast into individual statistics distributions instead of one combined field (#188)
- Add a distinct "Cover beibehalten" (keep current cover) option to the refresh cover picker (#187), and preselect metadata-refresh options that already match the item's current state (#186)
- Beep on a successful camera scan and auto-scroll to the capture results (#177), improving camera barcode recognition accuracy (#178) and accounting for the sticky header when auto-scrolling to search results (#172)
- Cache-bust a media item's cover URL so a replaced cover updates without a page reload (#171)
- Carry a NoEAN search candidate's cover through to the created item (#166)
- Make TMDB search honor the requesting user's preferred language (#170)

### Changed
- Preserve `created_at`/`updated_at` through backup/restore instead of resetting them to the restore time (#154)
- Preserve library owner and `is_sample_library` in backup/export (#152), and `captured_by_user_id` via a resolvable email rather than a raw, instance-local user ID (#153)
- Merge same-named providers across media types in the capture-source report (#149)
- Bump the Amazon metadata plugins to v0.2-beta (#183)
- Translate user levels (guest/user/admin) (#182)
- Reintroduce Amazon DVD/Blu-ray cast extraction on a confirmed real "Actors" bullet, after removing it once for being unreliable (#150, #173)
- Rename TMDB's config field from `api_key` to `read_access_token`, distinct from its short v3 API key

### Fixed
- Remove Amazon DVD/Blu-ray cast extraction after confirming it was unreliable in general, later reintroduced correctly (#150)
- Fix redundant/leaking `captured_by` relation key in backup export, and resolve `captured_by_email` unconditionally, not only when restoring settings
- Harden the `cover_url` SSRF guard, including hostnames and redirects, against server-side request forgery (#184)
- Pin the cover-download request to its already-validated IPs (#83)
- Warn about the weak default `MEDINV_DB_PASSWORD` for mariadb/postgres (#185)
- Fix HardcoverProvider's config test returning a false negative against a real, valid token

## [0.6] - 2026-08-20

### Added
- Add a real search mask with filters, sorting, and saved searches (#73), including a sidebar entry (#120), scroll-to-results on submit (#122), per-attribute media-type applicability hints (#123), and a location column with the rest of the result table (#109)
- Add PDF export for search results (#121, sorted and positioned above the result count, #127, #128) and for reports/single-library inventories (#87), rendered in the requesting user's language (#113)
- Add cross-library reports: sharing/activity statistics and capture attribution (#74), split into a list page and per-report detail pages (#101) with clickable, directly-editable results (#102); sharing overview and per-user activity moved from Statistics to Reports (#103)
- Add three random cover carousels to the home page (#116), pausing while their detail dialog is open (#119) and no longer staying focused after closing it (#118)
- Add a location field to every media item (#96)
- Add JPC as a third web-scraping metadata source, for CD/DVD-Blu-ray (#130) and later books too (#131)
- Add Thalia as a second web-scraping metadata source (#129) — later removed, see below
- Add Google Gemini as a third LLM-backed metadata source (#66) and OpenAI/ChatGPT as a second (#65)
- Add Genre and Untertitel fields to DVD/Blu-ray media items (#140)
- Show each library's item count in the library overview (#95), and the current library within a media item's own detail view (#117)
- Show a loading indicator during the metadata search after a scan/EAN entry (#93), and whether a CD candidate has a track list at all (#97)
- Show runtime, track count, and release date columns for CD libraries (#98), and let a candidate cover be viewed enlarged during metadata merge review (#99)
- Let a CD's track list be edited both at manual entry (#92) and in the item edit form (#90), entering duration as minutes:seconds instead of raw seconds (#94)
- Add cover upload to the manual-add dialog (#75), and let a library's description be set at creation time (#88)
- Add library ownership transfer to oneself (#78), and let a library share grant write access, not just read (#79), restored on import/backup (#80)
- Let users delete their own account (#86)
- Add a daily cleanup job for old `login_attempts` rows (#84), and default to rotating daily logs instead of a never-expiring file (#85)
- Include saved searches in backups, nested under their owning user (#125)

### Changed
- Show search results as a sortable table, opening a hit without leaving the results (#100)
- Consolidate library edit/sharing/ownership into one dialog (#76), rendering library item lists as a sortable table (#77)
- Show every price consistently with a currency symbol (#107, #105), and replace free-text currency entry with a select field (#114)
- Format the top-lists report as tables instead of a bespoke list (#104), each with its own panel instead of one shared card (#112), aligned via a fixed colgroup (#115)
- Let table-heavy pages use the full available width (#111)
- Document the frontend `package.json` version-sync expectation (#126)
- Promote JPC metadata providers out of Beta, enabled by default (#145)

### Fixed
- Fix `field=tracks` matching every book/DVD-Blu-ray candidate instead of none, with a systematic regression test for the whole bug class (#124)
- Fix error handling, unnecessary full reloads, and stale sort/page state on library pages (#108, #109, #110)
- Add missing "nothing to show" states to top-lists/capture-source reports, and skip an unneeded `/libraries` fetch (#106)
- Handle failed loads on Statistics/Reports, showing `total_value` with a currency symbol (#105)
- Fix a root-owned daily log file breaking login (#91)
- Refocus the EAN/ISBN field once no capture result is pending (#89)
- Throttle repeat scans of the same EAN, fixing the review dialog they used to corrupt
- Match a CD's `tracks[].title` precisely instead of the whole JSON blob (#72), and add `MediaCd::tracks` to search (#57)
- Strip `captured_by_user_id` from ordinary export/import, so an importing instance's own user IDs can never be falsely attributed (#148)
- Skip a path-traversal cover entry instead of failing the whole import (#147)
- Fix JPC scraper's search endpoint, which never actually existed, so every JPC lookup silently returned no results (#133)
- Fix JPC scraper extracting almost no attributes and no cover, from a `<b>`-wrapped-label parsing bug; add price/track extraction (#135)
- Fix JPC scraper's `disc_count` staying stuck at 1 on multi-disc releases (#136), and `medium` redundantly repeating that count (#138)
- Strip an episode-count annotation from JPC genre (#143) and a "Hörprobe" track-preview label from JPC CD track titles (#144)
- Harden the Amazon "Format:" byline-contamination fix beyond just the suffix (#139)
- Fix an Amazon scraper drift (price/currency, book descriptions, byline "Format:" contamination) found via a one-time authorized live check (#137)
- Harden the JPC scraper against SSRF via off-host hrefs (#146)

### Removed
- Remove the Thalia metadata provider (#134): thalia.de runs Cloudflare bot-management with an active JS challenge, confirmed permanently blocking this provider rather than merely unreliable

### Security
- Fix a mass-assignment vulnerability in metadata import and an SSRF vulnerability in cover download

## [0.5] - 2026-08-17

First tagged release. MedInv's core feature set — multi-library media
management, capture, metadata plugins, backup/export, search, and
statistics — was largely built out during this period; individual
GitHub issues only started tracking most changes partway through it, so
this entry is a mix of issue-referenced and un-referenced commits.

### Added
- Multi-library media management for books, CDs, and DVDs/Blu-ray, with permission-scoped libraries (owner/admin, per-library read/write shares)
- Barcode/EAN capture via camera-based scanning (#8) and manual entry, with a manual media-creation UI as the fallback when no metadata match is found (briefing 7.1)
- A metadata provider plugin framework: OpenLibrary, Google Books (#20), Hardcover (#18, later live-verified), Discogs for CDs (#22, live-verified), MusicBrainz, UPCMDB (corrected from an early "UPCitemdb" typo and properly implemented), Amazon via web scraping marked Beta (#50), and Claude as the first LLM-backed provider (#59) — each versioned (#44), labeled API-vs-scraping (#55), and reporting per-provider request status during import (#53)
- A metadata re-lookup ("refresh") button on an already-captured item's detail view (#56), downloading and locally storing cover images found via metadata import (#6)
- Let a media item's cover be opened fullscreen from the detail view (#45)
- CD track listing (position/title/duration) with automatic runtime derivation (#48), editable at manual entry and in the item edit form
- A currency field for price (#58), mapped from Google Books, converted into the library's default currency at capture time (#64), flagging mixed-currency libraries (#62)
- Bulk-delete (#54) and bulk field-update (#63) actions for media items
- Automatic scheduled backups (#27) with configurable, mutually-exclusive retention (count/age), manual backups exempt from retention, showing why each backup was created (schedule vs. migration), and end-to-end restore (#2)
- Per-library export/import (briefing 9.1), including cover images and thumbnails as a zip, structured per-plugin settings (#29), rejecting malformed files with actionable errors
- Genre/language/year/publisher-artist-director statistics (#7) and value-over-time growth statistics (#30)
- Real typo-tolerant fuzzy search per database backend (#9)
- Library sharing with a frontend UI (#32) and test coverage for the whole access/sharing model (#33), ownership transfer with deletion protection for users who still own libraries (#34), and library-level, owner/admin-only editing
- OpenID Connect / OAuth 2.0 login (#16), deriving username/level from Pocket ID claims
- Admin-only language pack CRUD (#12) and file upload (replacing textarea paste-in) with runtime loading (briefing 11.4/17., #15), pre-installed with 14 bundled packs (German, English, French, Spanish, Japanese, Chinese, Italian, Portuguese, Dutch, Polish, Russian, Ukrainian, Turkish, and the Nordic languages), matched against a first-time visitor's browser language and configurable as an admin default
- A full UI-template plugin infrastructure (#11) with 6 bundled CSS themes and a live swatch preview
- Real CIDR-range matching for `MEDINV_TRUSTEDIP` (#4), and global session-expired (401) / account-deactivated (403) handling across the frontend (#5)
- A Docker image (GitHub Actions build/publish, HEALTHCHECK wired to Laravel's own `/up` route) as the primary deployment path
- Structured application logging across authentication, admin actions, backups, and outgoing metadata-provider requests
- A consistent, shared panel design language applied across every admin/settings page, with a redesigned Capture page and custom settings page

### Fixed
- Fix Docker sqlite persistence: a volume-shadowing bug that silently discarded every database update on a container/image update, and a related data-loss bug (#25)
- Fix OpenLibrary import mishandling format, isbn10, and multi-author books (#28)
- Fix GoogleBooksProvider's search endpoint omitting fields only its by-ID endpoint returned
- Fix two real DiscogsProvider bugs: a missing cover and a wrong release date, and covers lost when a barcode matched multiple releases
- Fix book cover providers downloading the smallest available image instead of the largest
- Fix a 500 error on every deactivated account's next request
- Fix search returning zero results (a missing search field on the results page)
- Fix a broken `[program:scheduler]` supervisord section, and the scheduler logging even when it had nothing to do
- Fix a pre-update backup crashing when the update itself added a new database column
- Fix drag-to-reorder metadata plugins only working once (#41)
- Fix MusicBrainzProvider's base URL (`/ws2/release` → `/ws/2/release`)
- Merge metadata field-by-field across every provider instead of picking one whole record
- Stop leaking `system_settings` into an ordinary (non-backup) library export

### Changed
- Group metadata plugins by media type with drag-to-reorder priority
- Rebrand password-reset emails from Laravel's default to MedInv, bilingual
- Fall back the timezone default to the `TZ` environment variable instead of a hardcoded UTC
- Add a daily orphaned-cover-file cleanup with a grace period so a mid-capture file can't be swept up, toggleable via system settings (default on)

### Security
- Make `LibraryAccessService::canWrite()` check the user's account level, not just routing middleware (#35)
- Move `GET /metadata/plugins` behind `level:admin` — it was returning stored API keys to any logged-in user, guests included (#37)
- Move media-item read routes out from behind `level:user,admin`, which made a library shared with a guest effectively unusable (#38)
- Stop `LibraryController::show()` leaking the email addresses of specifically-shared users to any reader (#39)
- Make `canRead()`/`visibleLibrariesQuery()` check the user's level too, so a downgraded guest no longer keeps read access to a library they used to own (#40)
