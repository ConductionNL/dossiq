/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * First today draws the library's banner by its named export. Before
 * nextcloud-vue 2.81.0 the banner was only registered under the widget type
 * `banner`, a named import was undefined, Vue rendered an unknown
 * <cnbannerwidget> element and the My work grid kept an empty card for every
 * reader with a case past its term. These assertions pin that the installed
 * library exports it, and that the widget imports it.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const LIB = ['node_modules', '@conduction', 'nextcloud-vue', 'src']

describe('First today and the library banner', () => {
	const source = read('src', 'views', 'widgets', 'FirstTodayWidget.vue')

	it('imports the banner by name and renders it', () => {
		expect(source).toMatch(/import\s*\{[^}]*CnBannerWidget[^}]*\}\s*from '@conduction\/nextcloud-vue'/)
		expect(source).toContain('components: { CnBannerWidget }')
		expect(source).toContain('<CnBannerWidget')
	})

	it('relies on what the installed library actually exports', () => {
		const index = read(...LIB, 'index.js')
		expect(index).toMatch(/\bCnBannerWidget\b/)
	})
})
