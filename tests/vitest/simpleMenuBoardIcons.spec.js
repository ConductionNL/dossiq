/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The simple menu draws the icons of the DqZijbalk board.
 *
 * The board's navigation uses Material Design Icons: an inbox for My work, a
 * bulleted list for the team queue, a briefcase for All cases, a ticked box
 * for Tasks, two people for Contacts and a building for Organisations. The
 * manifest names them (a profile takes icons from the manifest), and every
 * name is registered: an unregistered name draws no icon at all.
 *
 * @spec openspec/specs/menu-board-icons/spec.md#requirement-the-menu-entries-carry-the-boards-icons
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

const BOARD_ICONS = {
	Dashboard: 'ViewDashboardOutline',
	WorkGroup: 'InboxOutline',
	Queue: 'FormatListBulleted',
	Cases: 'BriefcaseOutline',
	WorkflowBoard: 'ViewColumnOutline',
	Tasks: 'CheckboxMarkedOutline',
	Contacts: 'AccountMultipleOutline',
	OrganisationsMenu: 'Domain',
	Documentation: 'HelpCircleOutline',
}

/**
 * The icon of a menu entry, wherever the profile put it.
 *
 * @param {Array<object>} menu The built menu.
 * @param {string} id The entry id.
 * @return {string|undefined} Its icon.
 */
function iconOf(menu, id) {
	for (const item of menu) {
		if (item.id === id) {
			return item.icon
		}
		const nested = iconOf(item.children ?? [], id)
		if (nested !== undefined) {
			return nested
		}
	}
	return undefined
}

describe('the simple menu icons', () => {
	const simple = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json'))

	it.each(Object.entries(BOARD_ICONS))('draws %s with %s, as the board does', (id, icon) => {
		expect(iconOf(simple.menu, id)).toBe(icon)
	})

	it('registers every icon it names', () => {
		const icons = read('src', 'icons.js')
		for (const icon of Object.values(BOARD_ICONS)) {
			expect(icons, icon).toMatch(new RegExp(`^import ${icon} from 'vue-material-design-icons/${icon}\\.vue'$`, 'm'))
			expect(icons, icon).toMatch(new RegExp(`^\\t${icon},$`, 'm'))
		}
	})
})
