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

/**
 * Load `@conduction/nextcloud-vue/webpack`, or null when it is not installed.
 *
 * @return {object|null} The build-helper module.
 */
function loadLibraryWebpackHelpers() {
	try {
		return require('@conduction/nextcloud-vue/webpack')
	} catch {
		return null
	}
}

/**
 * The `appVersion` define for webpack.
 *
 * A build cannot know the version it will be installed as: the release
 * workflow writes the release into `appinfo/info.xml` after the bundle is
 * built, so dossiq 0.4.48-beta still said "dossiq 0.4.47-unstable" in the
 * settings footer. The library's `appVersionDefine()` returns an expression
 * that reads the installed version from the page's `version` initial state
 * (provided by DashboardController) in the browser, with the info.xml version
 * as fallback. A library without that helper gets the info.xml literal, as
 * before.
 *
 * @param {string} appId The app id.
 * @param {object} [options] Options.
 * @param {string} [options.appRoot] The app checkout root.
 * @param {object|null} [options.library] The library's webpack helpers; read
 *   from node_modules when omitted.
 * @return {string} The code webpack pastes in for `appVersion`.
 * @spec openspec/changes/notification-labels-and-tour-titles/specs/notification-labels/spec.md
 */
function appVersionDefinition(appId, options = {}) {
	const buildVersion = readAppVersion(options.appRoot)
	const library =
		options.library === undefined ? loadLibraryWebpackHelpers() : options.library
	if (library && typeof library.appVersionDefine === 'function') {
		return library.appVersionDefine(appId, buildVersion)
	}
	return JSON.stringify(buildVersion)
}

module.exports = { appVersionDefinition, readAppVersion, versionFromInfoXml }
