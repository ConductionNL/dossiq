/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein: De melding names the handler, not the user id, in
 * three columns of facts.
 *
 * @spec openspec/changes/report-card-handler-name/specs/case-page/spec.md#requirement-the-report-card-names-the-handler
 * @spec openspec/changes/report-card-handler-name/specs/case-page/spec.md#requirement-the-report-card-lays-its-facts-out-in-three-columns
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8')

/**
 * Every widget with this id anywhere in the simple layout.
 *
 * @param {unknown} node A layout node.
 * @param {string} id The widget id.
 * @param {Array<object>} out The matches.
 * @return {Array<object>}
 */
function find(node, id, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => find(child, id, out))
	} else if (node && typeof node === 'object') {
		if (node.id === id) {
			out.push(node)
		}
		Object.values(node).forEach((child) => find(child, id, out))
	}
	return out
}

describe('the report card (DqSociaalDomein De melding)', () => {
	it('shows the handler by display name', () => {
		const [card] = find(JSON.parse(layout), 'case-summary')
		const handler = card.content.fields.find((field) => field.path === 'assignee')
		expect(handler.format).toBe('user')
	})

	it('lays its facts out in three columns, as drawn', () => {
		const [card] = find(JSON.parse(layout), 'case-summary')
		expect(card.content.columns).toBe(3)
	})
})
