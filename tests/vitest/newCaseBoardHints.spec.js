// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The New case form in the simple structure reads as the DqNieuweZaak board:
 * the confirm button says "Zaak aanmaken" and every field carries the board's
 * helper line, or none where the board draws none. The schema's own
 * descriptions are written for the data model ("Reference to the case
 * type"), not for the person filling the form in.
 *
 * @spec openspec/specs/new-case-board-hints/spec.md#requirement-the-new-case-form-carries-the-boards-helper-lines
 */
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import simple from '../../src/menu-layout.simple.json'

const BOARD_HINTS = {
	caseType: ['Determines the statuses, the term and the documents', 'Bepaalt de statussen, de termijn en de documenten'],
	title: ['', ''],
	requester: ['', ''],
	intakeChannel: ['How the request came in', 'Hoe het verzoek binnenkwam'],
	description: ['', ''],
	plannedEndDate: ["From the case type's term", 'Uit de termijn van het zaaktype'],
	assignee: ['', ''],
	confidentiality: ['', ''],
	impact: ['How much it matters if it goes wrong', 'Hoeveel het uitmaakt als het misgaat'],
	urgency: ['How fast the case matters', 'Hoe snel de zaak ertoe doet'],
}

/**
 * The simple structure's New case form on the Cases page, the one the board
 * draws (eyebrow "Alle zaken").
 *
 * @return {Array<object>} The open-form actions.
 */
function newCaseForms() {
	const cases = simple.pages.find((page) => page.id === 'Cases')
	return (cases.configAppend?.headerActions ?? []).filter((action) => action.id === 'new-case')
}

describe('New case in the simple structure, against DqNieuweZaak', () => {
	it('labels the confirm button Zaak aanmaken', () => {
		const forms = newCaseForms()
		expect(forms).toHaveLength(1)
		for (const form of forms) {
			expect(form.formConfirmLabel).toBe('Create case')
		}
		expect(nl.translations['Create case']).toBe('Zaak aanmaken')
	})

	it.each(Object.entries(BOARD_HINTS))('gives %s the board helper line', (key, [en, dutch]) => {
		for (const form of newCaseForms()) {
			expect(form.fieldOverrides[key]?.description, key).toBe(en)
			if (en !== '') {
				expect(nl.translations[en]).toBe(dutch)
			}
		}
	})

	it('opens with the board subtitle, word for word', () => {
		const forms = newCaseForms()
		expect(forms.length).toBeGreaterThan(0)
		for (const form of forms) {
			expect(nl.translations[form.formSubtitle]).toBe('Velden met * zijn verplicht. De zaak start in de eerste status van het zaaktype. De ontvangstdatum en de startdatum vult het systeem zelf in, vanaf het moment dat het verzoek binnenkwam.')
		}
	})
})

