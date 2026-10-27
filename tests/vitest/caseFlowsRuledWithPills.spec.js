/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's Flows card as DqZaak draws it: the runs only, ruled, each
 * with its trigger in words, a status pill and when it started (nextcloud-vue
 * CnFlowRunsWidget statusPill); and its Werkinstructie card, the page's title
 * and where it lives. Read from the layout and the catalogue.
 *
 * @spec openspec/changes/flow-runs-ruled-with-pills/specs/cn-flow-runs-widget/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(root, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

/**
 * The first object at any depth that holds `key`.
 *
 * @param {unknown} node A node.
 * @param {string} key The key.
 * @return {object|null}
 */
function find(node, key) {
	if (node === null || typeof node !== 'object') {
		return null
	}
	if (!Array.isArray(node) && Object.hasOwn(node, key)) {
		return node[key]
	}
	for (const child of Object.values(node)) {
		const hit = find(child, key)
		if (hit !== null) {
			return hit
		}
	}
	return null
}

describe('the case Flows card', () => {
	const content = find(layout, 'case-flow-runs').content

	it('draws the runs ruled with pills, the start moment and no heading or quiet line', () => {
		expect(content.statusPill).toBe(true)
		expect(content.age).toBe('datetime')
		expect(content.historyTitle).toBe(false)
		expect(content.quietLineWithHistory).toBe(false)
		expect(content.subject).toBe('@objectId')
	})

	it('names the statuses and triggers in Dutch as the board does', () => {
		expect(nl[content.statusLabels.completed]).toBe('Gelukt')
		expect(nl[content.statusLabels.failed]).toBe('Mislukt')
		expect(nl[content.triggerLabels['object.created']]).toBe('Gestart bij het aanmaken van de zaak')
		expect(nl[content.triggerLabels['object.updated']]).toBeTruthy()
	})
})

describe('the Werkinstructie card', () => {
	it('names the page, where it lives and where more pages are (DqZaak), from the case type', () => {
		const text = JSON.stringify(layout)
		expect(text).toContain('"label":"{knowledgeBasePage:last-segment}"')
		const note = 'From Collectives, team {knowledgeBasePage:first-segment}. More pages under More, Knowledge.'
		expect(text).toContain(`"note":"${note}"`)
		expect(nl[note]).toBe('Uit Collectives, team {knowledgeBasePage:first-segment}. Meer pagina\'s onder Meer, Kennis.')
	})
})
