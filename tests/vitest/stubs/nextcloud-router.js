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
