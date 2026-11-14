// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The bundle's `appVersion` must be the version Nextcloud installs.
 *
 * The defect this pins down: webpack defined `appVersion` from
 * `package.json` (`0.1.0`, never bumped) while releases are written into
 * `appinfo/info.xml`, so the settings dialog footer read "dossiq 0.1.0" on
 * every install. The build now reads info.xml; this checks the reader
 * against the real manifest and against `package.json`, which must not be
 * the source any more.
 */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const {
	appVersionDefinition,
	readAppVersion,
	versionFromInfoXml,
} = require('../../scripts/appVersion.js')
const ROOT = path.resolve(__dirname, '../..')

describe('appVersion', () => {
	it('parses the version element out of an info.xml document', () => {
		expect(
			versionFromInfoXml(
				'<info>\n\t<version>0.3.16-unstable.1</version>\n</info>',
			),
		).toBe('0.3.16-unstable.1')
		expect(versionFromInfoXml('<info></info>')).toBeNull()
	})

	it('reads the shipped appinfo/info.xml, not package.json', () => {
		const infoXml = readFileSync(path.join(ROOT, 'appinfo/info.xml'), 'utf8')
		const expected = versionFromInfoXml(infoXml)
		expect(expected, 'appinfo/info.xml carries a version').toBeTruthy()
		expect(readAppVersion(ROOT)).toBe(expected)
		expect(readAppVersion(ROOT)).not.toBe('0.1.0')
	})

	it('is what webpack defines as the appVersion global', () => {
		const config = readFileSync(path.join(ROOT, 'webpack.config.js'), 'utf8')
		expect(config).toMatch(/appVersion: appVersionDefinition\(appId\)/)
		expect(config).not.toMatch(
			/appVersion: JSON\.stringify\(process\.env\.npm_package_version\)/,
		)
	})

	it('reads the installed version in the browser when the library can', () => {
		// The release writes its version into info.xml after the bundle is
		// built, so a build-time literal read "dossiq 0.4.47-unstable" on a
		// 0.4.48-beta install. The library helper returns an expression that
		// reads the page's `version` initial state instead.
		const library = {
			appVersionDefine: (appId, fallback) =>
				`read(${JSON.stringify(appId)}, ${JSON.stringify(fallback)})`,
		}
		const expected = readAppVersion(ROOT)
		expect(appVersionDefinition('dossiq', { appRoot: ROOT, library })).toBe(
			`read("dossiq", ${JSON.stringify(expected)})`,
		)
	})

	it('stops the build on a library without the helper, which is the control', () => {
		// The former fallback shipped the build-time info.xml literal without a
		// word, which is how "dossiq 0.4.47-unstable" reached a 0.4.48-beta.
		expect(() =>
			appVersionDefinition('dossiq', { appRoot: ROOT, library: {} }),
		).toThrow(/appVersionDefine/)
		expect(() =>
			appVersionDefinition('dossiq', { appRoot: ROOT, library: null }),
		).toThrow(/appVersionDefine/)
	})

	it('uses the real library helper when webpack loads its config', () => {
		// No injected library: this is the module webpack.config.js runs.
		const helpers = require('@conduction/nextcloud-vue/webpack')
		expect(typeof helpers.appVersionDefine).toBe('function')
		const expected = readAppVersion(ROOT)
		const code = appVersionDefinition('dossiq', { appRoot: ROOT })
		expect(code).toBe(helpers.appVersionDefine('dossiq', expected))
		expect(code).not.toBe(JSON.stringify(expected))
		expect(code).toContain('initial-state-dossiq-version')
	})

	it('builds against a library that has the helper', () => {
		const pkg = JSON.parse(readFileSync(path.join(ROOT, 'package.json'), 'utf8'))
		const range = pkg.dependencies['@conduction/nextcloud-vue']
		const [major, minor, patch] = range
			.replace(/^[\^~]/, '')
			.split('.')
			.map(Number)
		expect(major).toBe(2)
		expect(minor * 1000 + patch).toBeGreaterThanOrEqual(73 * 1000 + 1)
		const installed = require('@conduction/nextcloud-vue/package.json').version
		expect(installed.split('.').map(Number)[1]).toBeGreaterThanOrEqual(73)
	})
})
