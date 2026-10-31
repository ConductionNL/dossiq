/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A stalled case is one nobody worked on: days idle and the stalled lists read
 * the case's lastActivityAt, not @self.updated, which every system save moves.
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/dossiq_register.json'
import fragment from '../../lib/Settings/register.d/99-case-last-activity-at.json'
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

/**
 * Every stalled-cases widget, layout entry and config patch under a root.
 *
 * @param {object} root The tree.
 * @return {object[]} The nodes.
 */
function stalledNodes(root) {
	const found = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node !== null && typeof node === 'object') {
			if (node.id === 'stalled-cases' || node.widgetId === 'stalled-cases') {
				found.push(node)
			}
			if (node['stalled-cases'] !== undefined) {
				found.push(node['stalled-cases'])
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(root)
	return found
}

describe('a case\'s last activity', () => {
	it('is a date-time on the case', () => {
		expect(fragment.components.schemas.case.properties.lastActivityAt).toMatchObject({ type: 'string', format: 'date-time' })
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

	it('lists only cases idle for more than a week, and View all opens the same cases', () => {
		const stalled = [manifest, simple].flatMap((root) => stalledNodes(root))
		const lists = stalled.filter((widget) => widget.content !== undefined)
		const links = stalled.flatMap((widget) => [widget.headerLink, widget.content?.viewAllRoute]).filter(Boolean)
		expect(lists.length).toBeGreaterThanOrEqual(4)
		expect(links.length).toBeGreaterThanOrEqual(4)
		for (const widget of lists) {
			const filter = (widget.content.source ?? widget.content).filter
			expect(filter['lastActivityAt[lt]']).toBe('@today-7d')
		}
		for (const link of links) {
			expect(link.query['lastActivityAt[lt]']).toBe('@today-7d')
		}
	})
})
