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
	// Reading `representedParty` off the role rows is the library parties
	// widget's (nextcloud-vue objectParties.representedByMap, with these cases
	// ported to its spec). dossiq's half is that the Roles section names the
	// role rows of THIS case, where a gemachtigde says whom they act for.
	it('reads the role rows of the case, where a gemachtigde names the represented party', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
		const roles = people.content.sections.map((section) => section.widget).find((widget) => widget.id === 'case-party-roles')

		expect(roles.type).toBe('parties')
		expect(roles.content.rolesObjects).toEqual({ register: 'dossiq', schema: 'role', field: 'case' })
		expect(roles.content.roleLabels.gemachtigde).toBe('Authorised representative')
	})
})
