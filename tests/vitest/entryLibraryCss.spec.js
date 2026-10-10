/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every webpack entry that imports the library also imports its stylesheet.
 *
 * The library's component styles live in one built sheet,
 * `@conduction/nextcloud-vue/css/index.css`, and webpack does not pull it in
 * through the JS import. `src/main.js` imports it; `src/personalSettings.js`
 * did not, so Settings > Personal > Dossiq rendered the notification matrix
 * without its CSS: the channel header read "Notifications Bundle these" on
 * one line, with no borders and no stacking.
 *
 * @spec openspec/changes/r4-tour-menu-labels-and-settings-styles/specs/personal-settings-surface/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const webpackConfig = fs.readFileSync(path.join(ROOT, 'webpack.config.js'), 'utf8')
const entries = [
	...webpackConfig.matchAll(/path\.join\(__dirname, 'src', '([^']+\.js)'\)/g),
].map((m) => m[1])
const LIBRARY_IMPORT = /from '@conduction\/nextcloud-vue'/
const LIBRARY_CSS = /^import '@conduction\/nextcloud-vue\/css\/index\.css'$/m

describe('entries that use the library load its stylesheet', () => {
	it('reads the entries from webpack.config.js', () => {
		expect(entries).toContain('main.js')
		expect(entries).toContain('personalSettings.js')
	})

	const users = entries.filter((entry) =>
		LIBRARY_IMPORT.test(fs.readFileSync(path.join(ROOT, 'src', entry), 'utf8')),
	)

	it('finds the entries that import the library', () => {
		expect(users).toEqual(
			expect.arrayContaining(['main.js', 'personalSettings.js']),
		)
	})

	it.each(users)('🔴 %s imports the library stylesheet', (entry) => {
		expect(fs.readFileSync(path.join(ROOT, 'src', entry), 'utf8')).toMatch(
			LIBRARY_CSS,
		)
	})
})
