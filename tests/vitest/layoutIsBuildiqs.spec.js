// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A page's layout is the administrator's, changed through buildiq under the
 * buildiq button, and the same for every reader (Ruben, 18 October 2026).
 *
 * So no dossiq page keeps a reader's own arrangement (`userLayout`): with it,
 * CnDashboardPage saves a reader's drag as that reader's own arrangement
 * straight away, so the page differs per reader. Without it, the drag is
 * written into the page's own layout items, which is what buildiq's editor
 * diffs. While buildiq edits, a drag goes into the page layout either way.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))

// nextcloud-vue's app installer (useAppInstaller) registers these on load;
// the real package pulls in NcDialog's stylesheet outside Vite.
vi.mock('@nextcloud/password-confirmation', () => ({
	addPasswordConfirmationInterceptors: vi.fn(),
	confirmPassword: vi.fn(),
	PwdConfirmationMode: { Strict: 'strict', Lax: 'lax' },
}))

const { default: CnDashboardPage } = await import(
	'@conduction/nextcloud-vue/src/components/CnDashboardPage/CnDashboardPage.vue'
)

describe('the layout is buildiq\'s', () => {
	it('lets no dossiq page keep a reader\'s own arrangement', () => {
		const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => readJson('src', 'manifest.d', name))
		const pages = [
			readJson('src', 'manifest.json'),
			readJson('src', 'menu-layout.simple.json'),
			readJson('src', 'menu-layout.json'),
			...fragments,
		].flatMap((doc) => doc.pages || [])

		expect(pages.filter((page) => page.config && 'userLayout' in page.config).map((page) => page.id)).toEqual([])
	})

	it('writes a drag on a page without userLayout into the page layout, which buildiq diffs', () => {
		const layout = [{ id: '1', widgetId: 'w1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 }]
		const context = { userLayout: false, userLayoutItems: null, layout, $emit: vi.fn() }

		CnDashboardPage.methods.onLayoutChange.call(context, [{ id: '1', widgetId: 'w1', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 3 }])

		expect(layout[0]).toMatchObject({ gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 3 })
		expect(context.$emit).toHaveBeenCalledWith('layout-change', layout)
	})

	/**
	 * A CnDashboardPage call context with userLayout on.
	 *
	 * @param {Array<object>} layout The page's own layout items.
	 * @param {boolean} editingBody Whether buildiq is editing the page.
	 * @return {object} The context, with `showsUserLayout` taken from the component.
	 */
	function userLayoutContext(layout, editingBody) {
		const context = {
			userLayout: true,
			editingBody,
			isEditing: false,
			userLayoutDirty: false,
			userLayoutItems: layout.map((item) => ({ ...item })),
			layout,
			$emit: vi.fn(),
			saveUserLayout: vi.fn(),
			geometryFrom: (u) => ({ gridX: u.gridX, gridY: u.gridY, gridWidth: u.gridWidth, gridHeight: u.gridHeight }),
		}
		context.showsUserLayout = CnDashboardPage.computed.showsUserLayout.call(context)
		return context
	}

	it('would have kept the same drag out of the page layout with userLayout on, saving it as the reader\'s own', () => {
		const layout = [{ id: '1', widgetId: 'w1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 }]
		const context = userLayoutContext(layout, false)

		CnDashboardPage.methods.onLayoutChange.call(context, [{ id: '1', widgetId: 'w1', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 3 }])

		expect(layout[0]).toMatchObject({ gridX: 0, gridY: 0 })
		expect(context.userLayoutItems[0]).toMatchObject({ gridX: 6, gridY: 2 })
		expect(context.saveUserLayout).toHaveBeenCalled()
	})

	it('sends a drag into the page layout while buildiq edits, even with userLayout on', () => {
		const layout = [{ id: '1', widgetId: 'w1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 }]
		const context = userLayoutContext(layout, true)

		CnDashboardPage.methods.onLayoutChange.call(context, [{ id: '1', widgetId: 'w1', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 3 }])

		expect(layout[0]).toMatchObject({ gridX: 6, gridY: 2 })
		expect(context.saveUserLayout).not.toHaveBeenCalled()
	})
})
