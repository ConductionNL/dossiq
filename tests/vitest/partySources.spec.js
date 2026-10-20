/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where dossiq searches for a party, and the company line in the reader's
 * language: "KvK 24157431" in Dutch.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => (text === 'CoC {number}' ? 'KvK {number}' : text)
		.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))

const { PARTY_SOURCES_WITH_CONTACTS, registerPartySources } = await import('../../src/components/party/partySources.js')

describe('party sources', () => {
	it('fills the company line with the translated KvK prefix and the number template', () => {
		const [person, company] = registerPartySources()
		expect(person).toMatchObject({ id: 'person', schema: 'brpPerson', label: 'Resident' })
		expect(company).toMatchObject({ id: 'company', schema: 'kvkCompany', label: 'Organisation', secondary: 'KvK {kvkNumber}' })
	})

	it('adds the reader\'s contacts for the start-a-case picker', () => {
		expect(PARTY_SOURCES_WITH_CONTACTS.map((s) => s.id)).toEqual(['person', 'company', 'contact'])
	})
})
