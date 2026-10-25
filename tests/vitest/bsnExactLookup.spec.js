/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every person source of the party picker looks a typed BSN up exactly,
 * through dossiq's guarded lookup, instead of free text.
 *
 * @spec openspec/changes/bsn-exact-lookup/specs/initiator-selection/spec.md#requirement-a-person-is-found-by-bsn-through-a-guarded-recorded-lookup
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * The person sources in a file.
 *
 * @param {string} file The JSON file.
 * @return {Array<object>}
 */
function personSources(file) {
	const out = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node && typeof node === 'object') {
			if (node.id === 'person' && node.schema === 'brpPerson') {
				out.push(node)
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8')))
	return out
}

describe('zoek op BSN', () => {
	it.each(['src/manifest.json', 'src/menu-layout.simple.json'])('every person source in %s looks a BSN up exactly', (file) => {
		const sources = personSources(file)
		expect(sources.length).toBeGreaterThan(0)
		for (const source of sources) {
			expect(source.lookup).toEqual({ url: '/apps/dossiq/api/people/lookup', when: '^[0-9]{9}$' })
		}
	})

	it('the initiator picker does too, and no longer prints the BSN', () => {
		const modal = fs.readFileSync(path.join(ROOT, 'src/modals/InitiatorPickerModal.vue'), 'utf8')
		expect(modal).toContain("lookup: { url: '/apps/dossiq/api/people/lookup', when: '^[0-9]{9}$' }")
		expect(modal).not.toContain('BSN {citizenServiceNumber}')
	})
})
