/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A stalled case is one nobody worked on: days idle and the stalled lists read
 * the case's lastActivityAt, not @self.updated, which every system save moves.
 *
 * @spec openspec/changes/case-last-activity-at/specs/case-last-activity-at/spec.md
 */

import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'

const caseSchema = register.components.schemas.case

/**
 * Every object under a root that carries this id.
 *
 * @param {object} root The tree.
 * @param {string} id   The widget id.
 * @return {object[]} The widgets.
 */
function widgetsWithId(root, id) {
	const found = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node !== null && typeof node === 'object') {
			if (node.id === id) {
				found.push(node)
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(root)
	return found
}

describe('a case\'s last activity', () => {
	it('is a date-time on the case', () => {
		expect(caseSchema.properties.lastActivityAt).toMatchObject({ type: 'string', format: 'date-time' })
	})

	it('is what days idle counts from, falling back to the creation date', () => {
		const days = JSON.stringify(caseSchema.configuration['x-openregister-calculations'].daysSinceActivity)
		expect(days).toMatch(/"coalesce":\[\{"prop":"lastActivityAt"\},\{"prop":"@self.created"\}\]/)
	})

	it('orders every stalled list, and none reads @self.updated', () => {
		const stalled = [...widgetsWithId(manifest, 'stalled-cases'), ...widgetsWithId(simple, 'stalled-cases')]
		expect(stalled.length).toBeGreaterThanOrEqual(3)
		for (const widget of stalled) {
			const text = JSON.stringify(widget)
			expect(text).not.toContain('@self.updated')
			expect(text).toContain('lastActivityAt')
		}
	})
})
