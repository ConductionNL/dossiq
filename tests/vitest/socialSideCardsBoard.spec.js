// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein side column: order, Consent as facts, Who behind initials.
 *
 * @spec openspec/changes/social-side-cards-read-like-the-board/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const layout = readJson('src', 'menu-layout.simple.json')
const caseDetail = layout.pages.find((page) => page.id === 'CaseDetail').config
const card = (id) => caseDetail.sideColumn.find((entry) => typeof entry === 'object' && entry.id === id)
const nl = readJson('l10n', 'nl.json').translations

describe('DqSociaalDomein side column', () => {
	it('lists notice, Consent, Who, then Status for Melding Jeugdwet', () => {
		const caseType = readJson('lib', 'Settings', 'packages', 'jeugdwet', 'case-type.json').caseType
		expect(caseType.sideCards).toEqual(['side-special-data-health', 'side-social-consent', 'side-social-people', 'case-banner-stack'])
	})

	it('draws the newest consent as label-left facts', () => {
		const consent = card('side-social-consent')
		expect(consent.type).toBe('facts')
		expect(consent.content.source).toMatchObject({ schema: 'toestemming', filter: { caseId: '@objectId' }, sort: { field: 'grantedDate', dir: 'desc' } })
		expect(consent.content.fields.map((field) => field.label)).toEqual(['Purpose', 'Given by', 'Recorded', 'Valid until'])
		expect(consent.content.fields.at(-1).emptyValue).toBe('Until it is withdrawn')
		expect(nl['Given by']).toBe('Gegeven door')
		expect(nl['Until it is withdrawn']).toBe('Tot intrekking')
	})

	it('withdraws the consent by ending it today, only while it can be withdrawn', () => {
		const action = card('side-social-consent').content.actions.find((entry) => entry.id === 'consent-withdraw')
		expect(action).toMatchObject({
			type: 'api-call',
			method: 'PATCH',
			url: '/apps/openregister/api/objects/dossiq/toestemming/@source.id',
			payload: { withdrawn: true, validTo: '@today' },
			confirm: true,
		})
		expect(action.visibleWhen.all).toEqual([
			{ field: 'withdrawalPossible', op: 'eq', value: true },
			{ field: 'withdrawn', op: 'neq', value: true },
		])
		expect(nl['Withdraw consent']).toBe('Toestemming intrekken')
	})

	it('draws each person behind their initials on one line', () => {
		const people = card('side-social-people').content
		expect(people.avatar).toBe('{name}')
		expect(people.title).toBe('{name}, {description}')
		expect(people.meta).toBeUndefined()
	})
})
