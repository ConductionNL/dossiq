/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tags take the tone their value carries: a working connection is green and
 * a task in progress is purple, as the boards draw them (DqInstellingen,
 * DqTaken).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { CONNECTION_STATUS_TONES } from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))

/**
 * Every column with this key anywhere in the overlay.
 *
 * @param {unknown} node The node to search.
 * @param {string} key The column key.
 * @param {Array<object>} out The columns found.
 * @return {Array<object>} The columns.
 */
function columnsWithKey(node, key, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => columnsWithKey(child, key, out))
	} else if (node && typeof node === 'object') {
		if (node.key === key && node.widget === 'badge') {
			out.push(node)
		}
		Object.values(node).forEach((child) => columnsWithKey(child, key, out))
	}
	return out
}

describe('board pill tones', () => {
	it('colours each connection status as the board draws it', () => {
		expect(CONNECTION_STATUS_TONES).toEqual({
			configured: 'success',
			error: 'error',
			simulated: 'info',
			unavailable: 'warning',
		})
	})

	it('wires the tones into the integrations table', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
		expect(source).toContain('widgetProps: { colorMap: CONNECTION_STATUS_TONES }')
	})

	it('draws a task in progress purple, keyed on the state itself', () => {
		const columns = columnsWithKey(simple, 'state')
		expect(columns.length).toBeGreaterThan(0)
		for (const column of columns) {
			expect(column.widgetProps.colorMap).toEqual({ enabled: 'info', active: 'purple', completed: 'success', terminated: 'error' })
		}
	})

	it('names a task state in the words of the task page (DqTaken "In uitvoering")', () => {
		const taskPage = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
			.pages.find((page) => page.id === 'TaskDetail')
		const columns = columnsWithKey(simple, 'state')
		for (const column of columns) {
			expect(column.enum).toEqual(['available', 'enabled', 'active', 'completed', 'terminated', 'disabled'])
			expect(column.enumLabels.active).toBe(taskPage.config.typePill.labels.active)
			expect(column.enumLabels.enabled).toBe('Ready to claim')
			expect(column.enumLabels.completed).toBe('Completed')
		}
		expect(columnsWithKey(simple, 'stateLabel')).toEqual([])
	})
})
