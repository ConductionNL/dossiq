/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The smaller case dialogs in the words of board DqZaakDialogen: a one-line
 * intro where a paragraph stood, "Naar een andere versie" with its current
 * version in a notice, and the board's field and button words. No field goes.
 *
 * @spec openspec/specs/case-dialogs/spec.md#requirement-a-case-dialog-is-titled-by-what-it-does
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'

const source = (name) => fs.readFileSync(path.resolve(__dirname, '../../src/dialogs', `${name}.vue`), 'utf8')
const dutch = (key) => nl.translations[key]

describe('case dialogs in the words of DqZaakDialogen', () => {
	it.each([
		['CaseSplitDialog', 'What you tick moves to the new case.', 'Wat je aanvinkt gaat naar de nieuwe zaak.'],
		['CaseMergeDialog', 'Parties, documents and objects move to the case you pick.', 'Partijen, documenten en objecten gaan naar de zaak die je kiest.'],
		['CaseCopyDialog', 'The copy gets its own case number.', 'De kopie krijgt een eigen zaaknummer.'],
		['CrossDomainLookupDialog', 'Only whether an open case exists elsewhere, and who to call.', 'Alleen of er elders een lopende zaak is, en wie je kunt bellen.'],
	])('%s opens with one short line', (name, key, words) => {
		expect(source(name)).toContain(`'${key}'`)
		expect(dutch(key)).toBe(words)
	})

	it('titles the version move as the menu does and shows the current version in a notice', () => {
		const move = source('CaseVersionMoveDialog')
		expect(move).toContain(":name=\"t('dossiq', 'To another version')\"")
		expect(move).toMatch(/<NcNoteCard v-if="current.version" type="info"/)
		expect([dutch('To another version'), dutch('Version to move to'), dutch('Move the case')]).toEqual(['Naar een andere versie', 'Versie om naar te gaan', 'Zaak verplaatsen'])
	})

	it('uses the board\'s words for merge and lookup', () => {
		expect(dutch('Search the case to merge into')).toBe('Zoek de zaak om in op te gaan')
		expect(dutch('Look up')).toBe('Opzoeken')
	})
})
