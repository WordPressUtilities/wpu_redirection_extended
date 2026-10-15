# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin

WordPress plugin **WPU Redirection Extended** — extends the third-party `redirection/redirection.php` plugin with extra tooling (CSV import/export, sitemap-driven CSV generation, 404 dashboard widgets, role management, slug/redirection conflict detection, DB cleanup).

- Requires the **Redirection** plugin (dependency checked in `check_dependencies()`).
- Single PHP class `WPURedirectionExtended` in `wpu_redirection_extended.php` (~2400 lines, split into `/* --- Section --- */` + `# SECTION` banners) drives everything; bumped via `$plugin_version` and the header `Version:` line (must stay in sync).
- PHP 8.0+, WP 6.9+ (see plugin header).
- No build step, no test suite. CI (`.github/workflows/php.yml`) only runs `php -l` on every PHP file under PHP 8.0 — run `php -l wpu_redirection_extended.php` locally after edits.

## Architecture

Everything is wired through hooks in `WPURedirectionExtended::__construct()`:

- **Admin page** — under `Tools` (`tools.php`), rendered by `page_content__main()` as tabs whose markup lives in `inc/tpl/admin-page-section-*.php`, form submissions dispatched in `page_action__main()` to `page_action__main__submit_*` handlers (CSV import, generate-CSV-from-sitemap, clean-database).
- **Sitemap → CSV**: `fetch_sitemap_urls()` recursively walks XML sitemaps / sitemap indexes (gzip supported) using `wp_remote_get()` only. Honors max-urls / max-children / depth / timeout limits.
- **CSV import**: `page_action__main__submit_csv()` parses uploaded CSV and creates Redirection plugin entries via its internal API.
- **Cleanup**: `page_action__main__clean_redirections()` flags duplicates, invalid regex queries, sources that match an existing slug and unresolvable internal targets (`page_action__main__clean_redirections__*`; `$diagnostic_only` = report without deleting). Also exposed via WP-CLI (`inc/wp-cli.php` → `wpu-redirection-extended-clean-database`) and the `wpu_redirection_extended_clean_database` action.
- **Slug-conflict notices**: `notice_slug_match_redirection*` shows admin warnings when a post/term slug collides with an existing redirection (regex or exact).
- **404 log filtering**: `filter_404_log_data()` hooks Redirection's `redirection_404_data` and returns `false` (skips the insert) when the URL contains one of the patterns (case-insensitive substring). Default list (wp-login, /admin/, .env, .yml…) is overridable via the `wpu_redirection_extended_404_ignore_patterns` filter (empty array = disabled).
- **404 spike alert**: daily cron `wpu_redirection_extended_check_404_spike` (`check_404_spike()`) emails via `WPUBaseNotify` when yesterday's 404s exceed thresholds (`wpu_redirection_extended_404_spike_thresholds`). Notification settings are the only WPUBaseSettings fields (rendered in the admin page "settings" tab, `create_page => false`). Cron is cleared on deactivation and in `uninstall.php`.
- **Front 404 quick-redirect form**: on front 404 pages, users with the plugin capability get a footer form (`inc/tpl/front-404-redirect-form.php`) with slug suggestions from `suggest_redirections_for_uri()` (token-set scoring); submitted to `admin-post.php?action=wpu_redir_ext_create_404`.
- **Incoming redirections metabox**: `add_metabox_incoming_redirections()` lists redirections targeting the edited post's URL.
- **Dashboard widgets**: `load_widget_types()` defines SQL queries (`bots`, `files`, `utm`) against `{$wpdb->prefix}redirection_404`. Widgets are registered in `add_dashboard_widgets()`; CSV export is handled by `handle_widget_csv_download()`. Extend via the `wpu_redirection_extended_widget_types` filter.
- **Autocomplete**: `extend_redirect_autocomplete()` filters `rest_request_after_callbacks` on `/redirection/v1/redirect/post` to add results from custom post types (excludes `post`, `page`, `attachment`).
- **Roles**: own capability `wpu_redirection_extended_access` granted to `administrator` + `super_editor`, plus a custom `redirection_manager` role. The Redirection plugin's required role is overridden via the `redirection_role` filter.

## WPUBase libraries (`inc/`)

Vendored shared helpers, namespaced to `\wpu_redirection_extended\` to avoid collisions with other WPU plugins:

- `WPUBaseAdminPage` — admin page boilerplate (menus, form rendering, nonces).
- `WPUBaseMessages` — admin notices queue (`set_message()` proxies to it; falls back to `error_log` / stdout in CLI).
- `WPUBaseToolbox` — utility methods (dependency checks, custom roles, etc.).

These are vendored copies — do not edit unless intentionally syncing upstream.

## Conventions

- All external HTTP calls **must** use `wp_remote_get()` / `wp_remote_post()` (already enforced — `fetch_sitemap_urls()` is the main consumer).
- Translations live in `lang/` with text domain `wpu_redirection_extended`. After adding/changing user-facing strings, regenerate `.po`/`.mo`/`.l10n.php`.
- Almost every tunable (limits, excluded URLs/post types, thresholds, patterns) is exposed through an `apply_filters('wpu_redirection_extended_…')` — prefer adding a filtered default over a new setting.
- `uninstall.php` deletes options directly; add any new option/cron there.
- Form fields rendered via `get_admin_field_html()` — reuse it instead of hand-writing inputs.
- When bumping the version, update **both** `$plugin_version` and the plugin header `Version:`.
