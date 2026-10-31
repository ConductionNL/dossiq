// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein side column: order, Consent as facts, Who behind initials.
 *
 * @spec openspec/specs/case-page/spec.md
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
		expect(caseType.sideCards).toEqual(['side-special-data-health', 'side-social-consent', 'side-social-people', 'side-social-history', 'case-banner-stack'])
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

	it('ends with the case history, reads left out, saying for which purpose the case was opened', () => {
		const history = card('side-social-history')
		expect(history.type).toBe('audit-trail')
		expect(history.visibleWhen).toEqual({ field: '@context.caseType.specialDataCategories', op: 'notEmpty' })
		expect(history.content.excludeActions).toEqual(['read'])
		expect(history.content.actionLabels['dossiq.case.access-purpose']).toBe('Case opened, purpose {changed.purpose}')
		expect(Object.keys(history.content.valueLabels.purpose)).toEqual(['handling', 'advice', 'quality-check', 'legal-obligation', 'data-subject-request', 'other'])
		expect(nl['Case opened, purpose {changed.purpose}']).toBe('Zaak ingezien, doel {changed.purpose}')
		expect(nl['Case history']).toBe('Historie')
		// The case's reference under the trail, as DqZaaktype's Historie (nextcloud-vue audit-trail footnote).
		expect(history.content.footnote).toBe('Reference {identifier}')
		// The consent's own trail joins the case's (nextcloud-vue relatedSources).
		expect(history.content.relatedSources).toEqual([{ as: 'consent', register: 'dossiq', schema: 'toestemming', filter: { caseId: '@objectId' }, limit: 3 }])
		expect(nl[history.content.actionLabels['consent.create']]).toBe('Toestemming vastgelegd')
		expect(nl['Reference {identifier}']).toBe('Kenmerk {identifier}')
	})

	/** @spec openspec/changes/social-history-people-and-files/specs/case-page/spec.md#requirement-the-social-history-shows-what-people-did-and-which-documents-they-viewed */
	it('shows what people did, leaving System jobs out, and names a viewed document', () => {
		const history = card('side-social-history').content
		expect(history.excludeActors).toEqual(['System'])
		expect(history.actionLabels['file.downloaded']).toBe('Viewed {changed.data.fileName}')
		expect(nl['Viewed {changed.data.fileName}']).toBe('Inzage {changed.data.fileName}')
	})

	it('puts "Overleg plannen" above a Melding Jeugdwet case, filing the meeting on the case', () => {
		const caseType = readJson('lib', 'Settings', 'packages', 'jeugdwet', 'case-type.json').caseType
		expect(caseType.quickActions).toEqual(['plan-case-meeting'])
		const page = layout.pages.find((entry) => entry.id === 'CaseDetail')
		const action = page.configAppend.headerActions.find((entry) => entry.id === 'plan-case-meeting')
		expect(action).toMatchObject({ type: 'open-form', schema: 'mdoOverleg', includeFields: ['consultationDate', 'agenda'], props: { caseIds: ['@objectId'] } })
		expect(JSON.stringify(action.visibleWhen)).toContain('Sociaal domein')
		expect(nl['Plan a case meeting']).toBe('Overleg plannen')
	})

	it('draws each person behind their initials on one line', () => {
		const people = card('side-social-people').content
		expect(people.avatar).toBe('{name}')
		expect(people.title).toBe('{name}, {description}')
		expect(people.meta).toBeUndefined()
	})

	/** @spec openspec/changes/social-people-stacked/specs/case-page/spec.md#requirement-the-who-card-is-a-short-unruled-list */
	it('lists the people as a short stacked list, no rule above them', () => {
		expect(card('side-social-people').content.density).toBe('stacked')
	})
})
