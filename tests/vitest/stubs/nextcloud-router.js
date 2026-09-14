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
 * it substitutes `{name}` placeholders from `params` and prefixes
 * `/index.php` to the result.
 *
 * The placeholder half used to be missing, so a caller that passes its
 * register and schema as params — which is how every OpenRegister objects URL
 * is built — got a URL with the literal braces still in it and no test could
 * assert which schema it had addressed.
 *
 * @param {string} url App-relative path, e.g. '/apps/openconnector/api/pdok'
 * @param {object} [params] Values for `{name}` placeholders in the path.
 * @return {string} The full index.php URL.
 */
export function generateUrl(url, params) {
	let path = url
	if (params && typeof params === 'object') {
		for (const [key, value] of Object.entries(params)) {
			path = path
				.split('{' + key + '}')
				.join(encodeURIComponent(String(value)))
		}
	}
	return '/index.php' + path
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
