/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/router used by the Vitest unit suite.
 *
 * The real package resolves the Nextcloud web root from the runtime, which
 * does not exist under Vitest. For the pdokService shim tests we only need a
 * deterministic generateUrl() that prefixes `/index.php` to the given path so
 * assertions can target the exact openconnector endpoint URL.
 */

/**
 * Mirror Nextcloud's generateUrl() with the default (web-root '') instance:
 * it prefixes `/index.php` to the supplied app-relative path.
 *
 * @param {string} url App-relative path, e.g. '/apps/openconnector/api/pdok'
 * @return {string} The full index.php URL.
 */
export function generateUrl(url) {
	return '/index.php' + url
}

/**
 * Mirror Nextcloud's generateRemoteUrl() with the default (web-root '')
 * instance: `remote.php` is served by the web server directly, so the url
 * carries NO `/index.php` prefix. That difference is the whole reason this
 * export exists.
 *
 * 🔴 A STUB THAT IS MISSING AN EXPORT DOES NOT FAIL LIKE A MISSING EXPORT.
 * `VersionHistoryPanel` moved from `generateUrl` to `generateRemoteUrl`,
 * because a `remote.php` path built with the former gains an `/index.php`
 * prefix wherever the front controller is inactive and routes nowhere. This
 * stub still exported only `generateUrl`, so the named import resolved to
 * `undefined`, calling it threw inside `fetchVersions()`, its catch emptied
 * the version list, and four tests failed reporting that the panel rendered
 * no Download and no Restore. The component's template had not changed at
 * all. Add the export here whenever a component starts using a new one,
 * rather than reading the empty render as a behaviour change.
 *
 * @param {string} service Service path, e.g. 'dav/versions/admin/versions/38'
 * @return {string} The full remote.php URL.
 */
export function generateRemoteUrl(service) {
	return '/remote.php/' + service
}
