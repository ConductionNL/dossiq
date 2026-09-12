// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// appVersion.js: the app version the bundle is built with.
//
// WHY THIS EXISTS
//
//   `webpack.config.js` defines the global `appVersion` that `@nextcloud/vue`
//   prints in the settings dialog footer. It used to read
//   `process.env.npm_package_version`, which is `package.json`'s version:
//   `0.1.0`, a number nobody bumps, while the app shipped as 0.3.x and the
//   release workflow writes every release into `appinfo/info.xml`. The
//   settings modal therefore said "dossiq 0.1.0" on a 0.3.16 install.
//
//   `appinfo/info.xml` is the one place Nextcloud reads the version from, so
//   it is the one place the bundle reads it from too. Plain CommonJS with no
//   dependencies so the webpack config can require it.

const fs = require('fs')
const path = require('path')

/**
 * Read the `<version>` element out of an `info.xml` document.
 *
 * @param {string} xml The document text.
 * @return {string|null} The version, or null when the element is absent.
 */
function versionFromInfoXml(xml) {
	const match = /<version>\s*([^<\s]+)\s*<\/version>/.exec(String(xml || ''))
	return match ? match[1] : null
}

/**
 * The app version for the build, from `appinfo/info.xml`.
 *
 * Falls back to `package.json`'s version only when the manifest cannot be
 * read, and says so on stderr, because a silent fallback is how the wrong
 * number shipped in the first place.
 *
 * @param {string} [appRoot] The app checkout root; defaults to this repo.
 * @return {string} The version string.
 */
function readAppVersion(appRoot = path.resolve(__dirname, '..')) {
	const infoXml = path.join(appRoot, 'appinfo', 'info.xml')
	try {
		const version = versionFromInfoXml(fs.readFileSync(infoXml, 'utf8'))
		if (version) return version
	} catch {
		// Fall through to the warning below.
	}
	const fallback = process.env.npm_package_version || '0.0.0'
	console.warn(
		`[appVersion] no <version> in ${infoXml}; falling back to ${fallback}`,
	)
	return fallback
}

module.exports = { readAppVersion, versionFromInfoXml }
