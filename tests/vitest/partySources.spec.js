/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where dossiq searches for a party, and the company line in the reader's
 * language: "KvK 24157431" in Dutch.
 *
 * The party field is the library's `party` form widget (CnPartyPicker); the
 * sources are manifest configuration on every field that names a requester or
 * a represented party. The start-a-case picker keeps the reader's contacts.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Every field override in a document that names a widget.
 *
 * @param {unknown} node The parsed document.
 * @param {Array<object>} found The overrides found so far.
 * @return {Array<object>} The `{ key, override }` pairs.
 */
function partyOverrides(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => partyOverrides(item, found))
	} else if (node && typeof node === 'object') {
		Object.entries(node).forEach(([key, value]) => {
			if (key === 'fieldOverrides' && value && typeof value === 'object') {
				Object.entries(value).forEach(([field, override]) => {
					if (override && override.widget === 'party') {
						found.push({ field, override })
					}
				})
			}
			partyOverrides(value, found)
		})
	}
	return found
}

const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const overrides = [
	...partyOverrides(read('src', 'manifest.json')),
	...partyOverrides(read('src', 'menu-layout.simple.json')),
]

describe('party sources', () => {
	it('declares the party widget on every requester and represented party', () => {
		// The second representedParty is the simple profile's Partij toevoegen form (DqZaakBetrokkenen).
		expect(overrides.map((o) => o.field).sort()).toEqual([
			'representedParty',
			'representedParty',
			...Array(9).fill('requester'),
		])
	})

	it('searches the person and company register sets, persons first', () => {
		for (const { override } of overrides) {
			const [person, company] = override.sources
			expect(person).toMatchObject({ id: 'person', register: 'dossiq', schema: 'brpPerson', label: 'Resident', title: '{displayName}' })
			expect(company).toMatchObject({ id: 'company', register: 'dossiq', schema: 'kvkCompany', label: 'Organisation', title: '{tradeName}', secondary: 'CoC {kvkNumber}' })
			expect(override.testid).toBe('initiator-picker-search')
			expect(override.placeholder).toBe('Name, BSN, trade name or KvK number')
		}
	})

	it('fills the company line with the translated KvK prefix', () => {
		expect(read('l10n', 'nl.json').translations['CoC {kvkNumber}']).toBe('KvK {kvkNumber}')
	})

	it('adds the reader\'s contacts for the start-a-case picker', () => {
		const modal = fs.readFileSync(path.join(ROOT, 'src', 'modals', 'InitiatorPickerModal.vue'), 'utf8')
		expect(modal).toContain("{ id: 'contact', type: 'contacts', label: 'Contact' }")
	})

	it('drops the InitiatorPicker registry key with the component', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(registry).not.toContain('InitiatorPicker: {')
		expect(registry).not.toContain('PartySearchField')
	})
})
