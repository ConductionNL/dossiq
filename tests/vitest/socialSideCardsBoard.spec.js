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
		expect(nl['Reference {identifier}']).toBe('Kenmerk {identifier}')
	})

	it('draws each person behind their initials on one line', () => {
		const people = card('side-social-people').content
		expect(people.avatar).toBe('{name}')
		expect(people.title).toBe('{name}, {description}')
		expect(people.meta).toBeUndefined()
	})
})
