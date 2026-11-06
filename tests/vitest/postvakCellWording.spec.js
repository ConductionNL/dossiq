// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The postvak words its cells as board DqPostvak does: the Voorstel pill
 * "Lijkt op 2026-0079" and Binnengekomen via "E-mail, gemeente@" (the channel
 * and the mailbox it came to), through nextcloud-vue's cell `textTemplate`.
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/88-postvak.json'), 'utf8'))
const columns = fragment.pages.find((p) => p.id === 'Postvak').config.columns
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('Postvak cell wording', () => {
	it('words the proposal as "Lijkt op {value}"', () => {
		const proposal = columns.find((col) => col.key === 'suggestion.identifier')
		expect(proposal.widgetProps.textTemplate).toBe('Looks like {value}')
		expect(nl['Looks like {value}']).toBe('Lijkt op {value}')
	})

	it('names the channel and the mailbox a message came to', () => {
		const via = columns.find((col) => col.label === 'Came in via')
		expect(via.key).toBe('channel')
		expect(via.widget).toBe('label')
		expect(via.enumLabels.email).toBe('E-mail')
		expect(via.widgetProps.textTemplate).toBe('{value}, {recipient|mailbox}')
		for (const label of Object.values(via.enumLabels)) {
			expect(nl[label]).toBeTruthy()
		}
	})
})
