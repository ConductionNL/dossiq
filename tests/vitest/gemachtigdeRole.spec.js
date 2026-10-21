// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every case type offers Gemachtigde, and a gemachtigde row says whom they act for.
 *
 * Three things here cannot be seen from a screenshot, and each one has a test.
 *
 * THE ORDER IS THE REQUIREMENT. "The case type's own rows, then the generic
 * ones" is an ordering, and an ordering that stops working looks exactly like
 * an instance whose role types happen to sort that way. So the assertions name
 * the rows in order rather than counting them.
 *
 * A GENERIC ROW LISTED TWICE is the failure a handler actually meets: two
 * entries called Gemachtigde with nothing on screen to tell them apart. The
 * deduplication is keyed on `genericRole`, so the test gives the case type's own
 * row that key and a different name.
 *
 * AN UNKNOWN CASE TYPE MUST NOT EMPTY THE PICKER. Returning the generic rows
 * alone for a case whose type could not be read would say this instance
 * declares no roles, on exactly the case where somebody is trying to add one.
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, text) => text,
}))

const { representedByMap } = await import('../../src/services/caseParties.js')

const manifest = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)

/** The Role field of the Add party form on the case page. */
const roleField = manifest.pages
	.find((page) => page.id === 'CaseDetail')
	.config.headerActions.find((action) => action.id === 'add-party')
	.fieldOverrides.roleType

describe('the roles a case type offers', () => {
	// The ordering, the deduplication and the unknown-type fallback are the
	// library's CnResourceSelect `scope` (tests/components/CnResourceSelectScope.spec.js).
	// What dossiq owns is the declaration that asks for them.
	it("offers the case type's own role types first, then the generic ones", () => {
		expect(roleField.widget).toBe('resource')
		expect(roleField.resource).toMatchObject({ register: 'dossiq', schema: 'roleType' })
		expect(roleField.resource.scope).toEqual({
			field: 'caseType',
			value: '@object.caseType',
			includeUnscoped: true,
			dedupeOn: 'genericRole',
		})
	})

	it('says a failed read rather than drawing an empty picker', () => {
		expect(roleField.resource.failedText).toBe(
			'The roles this case takes could not be read. Nothing has been changed on the case.',
		)
	})
})

describe('who a party is representing', () => {
	it('names the represented party for the participant acting for them', () => {
		const map = representedByMap([
			{
				participant: 'party-gem',
				roleType: 'rt-gem',
				representedParty: 'party-applicant',
			},
			{ participant: 'party-applicant', roleType: 'rt-handler' },
		])

		expect(map).toEqual({ 'party-gem': 'party-applicant' })
	})

	it('ignores delegateFrom, which is a date and not a party', () => {
		const map = representedByMap([
			{
				participant: 'party-gem',
				delegateFrom: '2026-09-01T00:00:00+00:00',
			},
		])

		expect(map).toEqual({})
	})

	it('reads a represented party handed over as a reference object', () => {
		const map = representedByMap([
			{ participant: 'p', representedParty: { id: 'q' } },
		])

		expect(map).toEqual({ p: 'q' })
	})

	it('answers an empty map for a case whose roles could not be read', () => {
		expect(representedByMap(null)).toEqual({})
	})
})
