/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein: the report card names the handler "Regisseur" for a
 * Jeugdwet case and "Behandelaar" elsewhere, from the case type's configuration.
 *
 * @spec openspec/changes/case-type-handler-label/specs/case-types/spec.md#requirement-a-case-type-names-its-handler
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

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

describe('the case type names its handler', () => {
	it('declares handlerLabel on the case type', () => {
		const schema = read('lib/Settings/register.d/99-case-type-handler-label.json').components.schemas.caseType
		expect(schema.properties.handlerLabel.type).toBe('string')
	})

	it('the Jeugdwet case type says Regisseur', () => {
		expect(read('lib/Settings/packages/jeugdwet/case-type.json').caseType.handlerLabel).toBe('Regisseur')
	})

	it('the report card labels the handler row from the case type, Handler by default', () => {
		const [card] = find(read('src/menu-layout.simple.json'), 'case-summary')
		const row = card.content.fields.find((field) => field.path === 'assignee')
		expect(row.labelPath).toBe('@context.caseType.handlerLabel')
		expect(row.label).toBe('Handler')
	})
})
