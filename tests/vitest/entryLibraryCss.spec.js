/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every webpack entry that imports the library gets its stylesheet: the page
 * that loads it links css/nextcloud-vue.css (copied there by the build, kept
 * parsed by the browser between loads), or the entry imports the sheet.
 *
 * The library's component styles live in one built sheet,
 * `@conduction/nextcloud-vue/css/index.css`, and webpack does not pull it in
 * through the JS import. `src/main.js` imports it; the personal settings
 * entry (retired 11 Oct 2026) once did not, so Settings > Personal > Dossiq
 * rendered the notification matrix without its CSS: the channel header read "Notifications Bundle these" on
 * one line, with no borders and no stacking.
 *
 * @spec openspec/specs/personal-settings-surface/spec.md
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
	})

	const users = entries.filter((entry) =>
		LIBRARY_IMPORT.test(fs.readFileSync(path.join(ROOT, 'src', entry), 'utf8')),
	)

	it('finds the entries that import the library', () => {
		expect(users).toEqual(expect.arrayContaining(['main.js']))
	})

	// The page that loads each linked entry, and the line that links the sheet.
	const LINKED_BY = {
		'main.js': 'templates/index.php',
		'settings.js': 'templates/settings/admin.php',
		'dashboardWidgets.js': 'lib/Dashboard/DashboardPageWidget.php',
	}
	const LINK = /addStyle\([^)]*'nextcloud-vue'\)/

	it.each(users)('🔴 %s gets the library stylesheet', (entry) => {
		const source = fs.readFileSync(path.join(ROOT, 'src', entry), 'utf8')
		if (LINKED_BY[entry]) {
			expect(fs.readFileSync(path.join(ROOT, LINKED_BY[entry]), 'utf8')).toMatch(LINK)
			expect(source).not.toMatch(LIBRARY_CSS)
		} else {
			expect(source).toMatch(LIBRARY_CSS)
		}
	})

	it('copies the library stylesheet to css/ in the build', () => {
		expect(webpackConfig).toContain("path.join(__dirname, 'css', 'nextcloud-vue.css')")
	})
})
