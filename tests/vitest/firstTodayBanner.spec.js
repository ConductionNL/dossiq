/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * First today draws the library's banner. nextcloud-vue registers that banner
 * under the widget type `banner` and does not export it by name, so a named
 * import is undefined, Vue renders an unknown <cnbannerwidget> element, and
 * the My work grid keeps an empty card under the view switch for every reader
 * with a case past its term. These assertions pin the lookup that works.
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

	it('takes the banner from the widget registry, not from a named import', () => {
		expect(source).not.toMatch(/import\s*\{[^}]*CnBannerWidget[^}]*\}\s*from '@conduction\/nextcloud-vue'/)
		expect(source).toContain("getWidgetTypeEntry('banner')")
		expect(source).toMatch(/:is="banner"/)
	})

	it('renders nothing while the registry has no banner', () => {
		expect(source).toMatch(/v-if="found && banner"/)
	})

	it('relies on what the installed library actually provides', () => {
		const index = read(...LIB, 'index.js')
		expect(index).toMatch(/getWidgetTypeEntry/)
		const registrar = read(...LIB, 'components', 'CnWidgetGrid', 'registerDashboardWidgets.js')
		expect(registrar).toContain("import '../CnBannerWidget/index.js'")
		expect(read(...LIB, 'components', 'CnBannerWidget', 'index.js')).toContain("registerDashboardWidget('banner'")
	})
})
