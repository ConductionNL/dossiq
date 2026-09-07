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
const { readAppVersion, versionFromInfoXml } = require('../../scripts/appVersion.js')
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
		expect(config).toMatch(/appVersion: JSON\.stringify\(readAppVersion\(\)\)/)
		expect(config).not.toMatch(
			/appVersion: JSON\.stringify\(process\.env\.npm_package_version\)/,
		)
	})
})
