/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein: the plan card and De melding find the family plan by
 * the plan's own caseId; the case holds no plan reference.
 *
 * @spec openspec/changes/family-plan-by-case/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-finds-its-family-plan-by-the-plans-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifestText = fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

/**
 * Every node with this id.
 *
 * @param {unknown} node A node.
 * @param {string} id The id.
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

describe('the family plan of a case', () => {
	it('the plan card reads the case\'s plan endpoint, not a field the case does not have', () => {
		expect(manifestText).not.toContain('@object.gezinsplanId')
		expect(manifestText.match(/\/apps\/dossiq\/api\/cases\/@objectId\/family-plan/g)).toHaveLength(2)
	})

	it('De melding reads the plan status from the plan that points at the case', () => {
		const [card] = find(layout, 'case-summary')
		expect(card.content.source).toEqual({ register: 'dossiq', schema: 'gezinsplan', filter: { caseId: '@objectId' }, sort: { field: 'preparedDate', dir: 'desc' } })
		const plan = card.content.fields.find((field) => field.label === 'Family plan')
		expect(plan.from).toBeUndefined()
		expect(nl[plan.map.draft]).toBe('In opbouw')
		expect(card.content.fields.filter((field) => field !== plan).every((field) => field.from === 'object')).toBe(true)
	})
})
