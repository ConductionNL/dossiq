/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the citizen-portal form helpers in src/utils/portaalForms.js:
 * validation rules and the exact request-body shaping for the DocumentList,
 * MessagingWidget, BezwaarForm and KlachtForm components. These assert the wire
 * shape sent to PortalMessageService / PortalRequestService, and that no client
 * identity field ever leaks into a payload (the backend derives the
 * pseudonymous subjectRef from the session).
 */

import { describe, it, expect } from 'vitest'
import {
	KLACHT_CATEGORIES,
	MAX_MESSAGE_LENGTH,
	validateMessage,
	buildMessagePayload,
	validateBezwaar,
	buildBezwaarPayload,
	validateKlacht,
	buildKlachtPayload,
	normaliseDocuments,
} from '../../src/utils/portaalForms.js'

describe('validateMessage', () => {
	it('rejects an empty case id', () => {
		const { valid, errors } = validateMessage({ caseId: '', content: 'hi' })
		expect(valid).toBe(false)
		expect(errors.caseId).toBeTruthy()
	})

	it('rejects an empty / whitespace-only body', () => {
		expect(validateMessage({ caseId: 'z1', content: '' }).valid).toBe(false)
		expect(validateMessage({ caseId: 'z1', content: '   ' }).valid).toBe(false)
	})

	it('rejects a body over the max length', () => {
		const long = 'a'.repeat(MAX_MESSAGE_LENGTH + 1)
		const { valid, errors } = validateMessage({ caseId: 'z1', content: long })
		expect(valid).toBe(false)
		expect(errors.content).toBeTruthy()
	})

	it('accepts a valid message', () => {
		expect(validateMessage({ caseId: 'z1', content: 'A question' }).valid).toBe(true)
	})
})

describe('buildMessagePayload', () => {
	it('trims and includes only the non-empty fields, never an identity', () => {
		const body = buildMessagePayload({ caseId: ' z1 ', content: ' hello ', caseReference: 'Z/2026/1', subject: 'Vraag' })
		expect(body).toEqual({ caseId: 'z1', content: 'hello', caseReference: 'Z/2026/1', subject: 'Vraag' })
		expect(body).not.toHaveProperty('bsn')
		expect(body).not.toHaveProperty('senderRef')
	})

	it('omits empty optional fields', () => {
		expect(buildMessagePayload({ caseId: 'z1', content: 'hi' })).toEqual({ caseId: 'z1', content: 'hi' })
	})
})

describe('validateBezwaar', () => {
	const base = { tegenZaakId: 'z1', decisionDate: '2026-04-02', motivering: 'Grounds', consent: true, binnenTermijn: true }

	it('accepts a complete, in-deadline, consented form', () => {
		expect(validateBezwaar(base).valid).toBe(true)
	})

	it('requires consent', () => {
		const { valid, errors } = validateBezwaar({ ...base, consent: false })
		expect(valid).toBe(false)
		expect(errors.consent).toBeTruthy()
	})

	it('requires motivering', () => {
		expect(validateBezwaar({ ...base, motivering: '  ' }).valid).toBe(false)
	})

	it('blocks submit when the deadline has passed', () => {
		const { valid, errors } = validateBezwaar({ ...base, binnenTermijn: false })
		expect(valid).toBe(false)
		expect(errors.deadline).toBeTruthy()
	})

	it('does not block when deadline status is unknown (null)', () => {
		expect(validateBezwaar({ ...base, binnenTermijn: null }).valid).toBe(true)
	})
})

describe('buildBezwaarPayload', () => {
	it('shapes the required + optional fields, dropping empties', () => {
		const body = buildBezwaarPayload({
			tegenZaakId: ' z1 ',
			decisionDate: '2026-04-02',
			motivering: ' my grounds ',
			tegenBeschikkingId: 'd9',
			onderwerp: 'Omgevingsvergunning',
			attachments: ['f1', 'f2'],
		})
		expect(body).toEqual({
			tegenZaakId: 'z1',
			decisionDate: '2026-04-02',
			motivering: 'my grounds',
			tegenBeschikkingId: 'd9',
			onderwerp: 'Omgevingsvergunning',
			attachments: ['f1', 'f2'],
		})
	})

	it('omits attachments when none', () => {
		const body = buildBezwaarPayload({ tegenZaakId: 'z1', decisionDate: '2026-04-02', motivering: 'g' })
		expect(body).not.toHaveProperty('attachments')
	})
})

describe('validateKlacht', () => {
	it('accepts a known category with a description', () => {
		expect(validateKlacht({ categorie: 'Bejegening', omschrijving: 'Onbeleefd' }).valid).toBe(true)
	})

	it('rejects an unknown category', () => {
		const { valid, errors } = validateKlacht({ categorie: 'Onzin', omschrijving: 'x' })
		expect(valid).toBe(false)
		expect(errors.categorie).toBeTruthy()
	})

	it('rejects an empty description', () => {
		expect(validateKlacht({ categorie: 'Andere', omschrijving: '' }).valid).toBe(false)
	})

	it('exposes the canonical category set matching the backend', () => {
		expect(KLACHT_CATEGORIES).toEqual(['Bejegening', 'Doorlooptijd', 'Communicatie', 'Medische/Zorgkwaliteit', 'Andere'])
	})
})

describe('buildKlachtPayload', () => {
	it('shapes category + trimmed description + optional employee', () => {
		const body = buildKlachtPayload({ categorie: 'Bejegening', omschrijving: ' rude ', betrokkenMedewerker: ' Balie ' })
		expect(body).toEqual({ categorie: 'Bejegening', omschrijving: 'rude', betrokkenMedewerker: 'Balie' })
	})

	it('omits the employee when empty', () => {
		expect(buildKlachtPayload({ categorie: 'Andere', omschrijving: 'x' })).toEqual({ categorie: 'Andere', omschrijving: 'x' })
	})
})

describe('normaliseDocuments', () => {
	it('returns [] for non-arrays', () => {
		expect(normaliseDocuments(null)).toEqual([])
		expect(normaliseDocuments(undefined)).toEqual([])
		expect(normaliseDocuments('x')).toEqual([])
	})

	it('maps fields and tolerates alternate key names', () => {
		const out = normaliseDocuments([
			{ id: 'a', naam: 'Beschikking', soort: 'besluit', datum: '2026-04-02' },
			{ id: 'b', title: 'Bouwtekening', documentType: 'tekening', creationDate: '2026-01-10' },
		])
		expect(out).toEqual([
			{ id: 'a', naam: 'Beschikking', soort: 'besluit', datum: '2026-04-02' },
			{ id: 'b', naam: 'Bouwtekening', soort: 'tekening', datum: '2026-01-10' },
		])
	})

	it('drops entries without an id and non-objects', () => {
		const out = normaliseDocuments([{ naam: 'no id' }, 'junk', { id: 'c', naam: 'ok' }])
		expect(out).toEqual([{ id: 'c', naam: 'ok', soort: '', datum: '' }])
	})
})
