/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A consent recorded on a case, and the consents widget that lists and
 * revokes it.
 *
 * The object the dialog writes is read by the share gate on the server, and
 * every rule pinned here fails silently when it breaks: a party in the wrong
 * key never covers a share, a missing `withdrawn: false` reads the same, and
 * a widget whose `visibleWhen` names the wrong case type flag never shows.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	CONSENT_SCHEMA,
	consentFormProblem,
	consentParties,
	consentPayload,
	isoDay,
} from '../../src/utils/consentRecord.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const complete = {
	grantedByName: ' J. de Vries ',
	grantedByBsn: '123456782',
	parties: 'Veilig Thuis, Wijkteam Noord ,',
	purpose: ' Afstemming hulpverlening ',
	from: '2026-11-01',
	until: '2027-11-01',
}

/**
 * The CaseDetail page config.
 *
 * @return {object} The config.
 */
function caseDetail() {
	return manifest.pages.find((entry) => entry.id === 'CaseDetail').config
}

describe('a consent form', () => {
	it('is complete with a BSN, a party, a purpose and a start date', () => {
		expect(consentFormProblem(complete)).toBe('')
		expect(consentFormProblem({ ...complete, until: '' })).toBe('')
	})

	it('names the first missing piece', () => {
		expect(consentFormProblem({ ...complete, grantedByBsn: '12345' })).toBe('bsn')
		expect(consentFormProblem({ ...complete, parties: ' , ' })).toBe('party')
		expect(consentFormProblem({ ...complete, purpose: '  ' })).toBe('purpose')
		expect(consentFormProblem({ ...complete, from: null })).toBe('from')
		expect(consentFormProblem({ ...complete, until: '2026-10-31' })).toBe('until')
		expect(consentFormProblem(null)).toBe('bsn')
	})

	it('reads parties from a list or a comma separated line, once each', () => {
		expect(consentParties('Veilig Thuis, veilig thuis ,Wijkteam Noord')).toEqual(['Veilig Thuis', 'Wijkteam Noord'])
		expect(consentParties([' GGD ', '', 'GGD'])).toEqual(['GGD'])
		expect(consentParties(undefined)).toEqual([])
	})

	it('turns picked dates into days', () => {
		expect(isoDay(new Date(2026, 0, 5))).toBe('2026-01-05')
		expect(isoDay('2026-03-04T10:00:00Z')).toBe('2026-03-04')
		expect(isoDay('not a date')).toBe('')
		expect(isoDay(new Date('nope'))).toBe('')
		expect(isoDay(null)).toBe('')
	})
})

describe('the consent object', () => {
	it('is the toestemming the share gate reads', () => {
		expect(CONSENT_SCHEMA).toBe('toestemming')
		expect(consentPayload(complete, ' case-1 ')).toEqual({
			caseId: 'case-1',
			grantedByBsn: '123456782',
			grantedByName: 'J. de Vries',
			recipientParties: ['Veilig Thuis', 'Wijkteam Noord'],
			tedoel: 'Afstemming hulpverlening',
			grantedDate: '2026-11-01',
			validTo: '2027-11-01',
			withdrawn: false,
			withdrawalPossible: true,
		})
	})

	it('leaves out a name and an end date nobody gave', () => {
		const payload = consentPayload({ ...complete, grantedByName: ' ', until: null }, 'case-1')
		expect(payload).not.toHaveProperty('grantedByName')
		expect(payload).not.toHaveProperty('validTo')
	})
})

describe('the consents widget on the case', () => {
	const config = caseDetail()
	const widget = config.widgets.find((entry) => entry.id === 'case-consents')

	it('lists the consents of this case and shows only when the case type requires consent', () => {
		expect(widget.type).toBe('object-list')
		expect(widget.content.schema).toBe('toestemming')
		expect(widget.content.filter).toEqual({ caseId: '@objectId' })
		expect(widget.visibleWhen).toEqual({ field: '@context.caseType.requiresConsentForSharing', op: 'eq', value: true })
		expect(config.layout.some((cell) => cell.widgetId === 'case-consents')).toBe(true)
	})

	it('records through the dialog and revokes by setting withdrawn', () => {
		expect(widget.content.createAction).toMatchObject({ type: 'open-modal', target: 'ConsentRecordDialog' })
		expect(registrySource).toMatch(/ConsentRecordDialog: \{\n\t\tkind: 'modal',\n\t\tcomponent: ConsentRecordDialog,/)
		const revoke = widget.content.rowActions.find((action) => action.id === 'revoke-consent')
		expect(revoke).toMatchObject({ type: 'object-op', op: 'patch', values: { withdrawn: true }, confirm: true })
		expect(revoke.visibleWhen).toEqual({ field: 'withdrawn', op: 'neq', value: true })
	})
})
