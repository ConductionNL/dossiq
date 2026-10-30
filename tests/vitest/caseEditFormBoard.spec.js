/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Zaak bewerken as board DqZaakDialogen draws it: the line under the title
 * ("De velden van de zaak. Status en termijn wijzigt u via Volgende stap."),
 * Behandelaar right after Zaaktype, and the requester asked as Verzoeker.
 *
 * @spec openspec/specs/case-dialogs/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import register from '../../lib/Settings/dossiq_register.json'
import nl from '../../l10n/nl.json'

const dutch = (key) => nl.translations[key] || key
const config = manifest.pages.find((page) => page.id === 'CaseDetail').config
const properties = register.components.schemas.case.properties

/**
 * The edit form's field order as CnFormDialog sorts it: an override's order wins over the schema's.
 *
 * @return {string[]} The included field keys in form order.
 */
function formOrder() {
	const order = (key) => {
		const own = config.fieldOverrides?.[key]?.order
		if (typeof own === 'number') return own
		return typeof properties[key]?.order === 'number' ? properties[key].order : Infinity
	}
	return [...config.includeFields].sort((a, b) => (order(a) - order(b)) || a.localeCompare(b))
}

describe('Zaak bewerken against DqZaakDialogen', () => {
	it('is titled Zaak bewerken while the header button keeps Bewerken', () => {
		expect(dutch(config.editTitle)).toBe('Zaak bewerken')
		expect(config.editLabel ?? '').toBe('')
	})

	it('says what the form does not change, in the board words', () => {
		expect(dutch(config.editSubtitle)).toBe('De velden van de zaak. Status en termijn wijzigt u via Volgende stap.')
	})

	it('asks Behandelaar right after Zaaktype, the requester last as Verzoeker', () => {
		expect(formOrder()).toEqual(['caseType', 'assignee', 'title', 'description', 'confidentiality', 'intakeChannel', 'startDate', 'plannedEndDate', 'impact', 'urgency', 'requester'])
		expect(dutch(config.fieldOverrides.assignee.label)).toBe('Behandelaar')
		expect(dutch(config.fieldOverrides.requester.label)).toBe('Verzoeker')
	})
})
