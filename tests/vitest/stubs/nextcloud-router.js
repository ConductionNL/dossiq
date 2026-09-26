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
	return '/index.php' + substitute(url, params)
}

/**
 * Substitute `{name}` placeholders in a path, shared by generateUrl() and
 * generateOcsUrl() so the two cannot drift apart.
 *
 * @param {string} url App-relative path.
 * @param {object} [params] Values for `{name}` placeholders in the path.
 * @return {string} The path with every placeholder replaced.
 */
function substitute(url, params) {
	let path = url
	if (params && typeof params === 'object') {
		for (const [key, value] of Object.entries(params)) {
			path = path
				.split('{' + key + '}')
				.join(encodeURIComponent(String(value)))
		}
	}
	return path
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

/**
 * Mirror Nextcloud's generateFilePath() with the default (web-root '')
 * instance and no `window.OC.coreApps`: the path is simply the app, the
 * type and the file, joined with slashes from the root.
 *
 * `src/publicPath.js` calls it to set webpack's public path, and
 * `@nextcloud/vue`'s reference picker calls it through imagePath() below,
 * at MODULE level, so a missing export is not a value that reads undefined
 * later on. It throws during import and takes the whole spec file with it.
 *
 * @param {string} app App id, e.g. 'dossiq' or 'core'.
 * @param {string} type Sub-directory, e.g. 'js' or 'img'. May be empty.
 * @param {string} file File name. May be empty.
 * @return {string} The root-relative path.
 */
export function generateFilePath(app, type, file) {
	const parts = ['']
	if (app) {
		parts.push(app)
	}
	if (type) {
		parts.push(type)
	}
	parts.push(file ?? '')
	return parts.join('/')
}

/**
 * Mirror Nextcloud's imagePath(): an extension-less name is taken to be an
 * SVG, and everything else is used as given, under the app's `img`
 * directory.
 *
 * @param {string} app App id, e.g. 'core'.
 * @param {string} file Image name, with or without an extension.
 * @return {string} The root-relative image path.
 */
export function imagePath(app, file) {
	return String(file).includes('.')
		? generateFilePath(app, 'img', file)
		: generateFilePath(app, 'img', file + '.svg')
}

/**
 * Mirror Nextcloud's generateOcsUrl() at OCS version 2: the same
 * `{name}` placeholder substitution as generateUrl(), under `/ocs/v2.php`.
 *
 * @param {string} url OCS-relative path, e.g. '/apps/files/api/v1/x'.
 * @param {object} [params] Values for `{name}` placeholders in the path.
 * @return {string} The full ocs/v2.php URL.
 */
export function generateOcsUrl(url, params) {
	return '/ocs/v2.php' + substitute(url, params)
}
