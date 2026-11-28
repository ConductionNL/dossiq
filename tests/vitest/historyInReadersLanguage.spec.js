/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case history reads in the reader's language (DqZaakHistorie): every
 * kind chip and every fixed term sentence has an English source with a Dutch
 * translation, in the simple and the full layout alike, and the words the
 * boards draw stand where the film audit found other ones.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const nl = read('l10n', 'nl.json').translations
const manifest = read('src', 'manifest.json')
const simple = read('src', 'menu-layout.simple.json')

/**
 * Every timeline widget content that declares kind labels.
 *
 * @param {object} node A manifest node.
 * @param {Array<object>} found The contents found so far.
 * @return {Array<object>} The contents.
 */
function timelines(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => timelines(child, found))
	} else if (node && typeof node === 'object') {
		if (node.manage === true && node.kindLabels && node.kindLabels.termijngebeurtenis) {
			found.push(node)
		}
		Object.values(node).forEach((child) => timelines(child, found))
	}
	return found
}

describe('the case history in the reader\'s language', () => {
	const contents = [...timelines(manifest), ...timelines(simple)]

	it('is declared in both layouts', () => {
		expect(contents).toHaveLength(2)
	})

	it('names every kind chip in English with a Dutch translation', () => {
		for (const content of contents) {
			expect(content.kindLabels.termijngebeurtenis).toBe('Deadline')
			for (const label of Object.values(content.kindLabels)) {
				expect(nl[label], label).toBeTruthy()
			}
		}
	})

	it('reads every stored term sentence through a key that translates back to that sentence', () => {
		for (const content of contents) {
			expect(content.messageKeys['Termijn gestart bij zaak-aanmaak']).toBe('Deadline started when the case was created')
			for (const [stored, key] of Object.entries(content.messageKeys)) {
				expect(nl[key], key).toBe(stored)
			}
		}
	})

	it('titles the card Case history, as the board does', () => {
		for (const content of contents) {
			expect(content.title).toBe('Case history')
		}
	})
})
