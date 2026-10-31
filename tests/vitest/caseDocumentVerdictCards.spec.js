/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten: on a case type that reviews its documents, the Files tab
 * opens with "4 van 7 beoordeeld" over the verdicts so far and closes with
 * "Weigeringsgronden tot nu toe", both read from the case's verdicts route.
 * The folder sits between them as the files browser in a card.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)
const VERDICTS = '/apps/dossiq/api/cases/@objectId/review/verdicts'
const REVIEWED = { field: '@context.caseType.documentReview.enabled', op: 'eq', value: true }

function filesTab(node) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = filesTab(child)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.label === 'Files' && (node.widgetId === 'case-files' || (node.widgetIds || []).includes('case-files'))) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = filesTab(child)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe('the Files tab on a case type that reviews its documents', () => {
	it('holds the folder with the verdicts inside its card, then the grounds', () => {
		expect(filesTab(simple).widgetIds).toEqual(['case-files', 'case-refusal-grounds-card'])
		expect(simple.configPatch.widgets['case-files'].content.view).toBe('tab')
		expect(simple.configPatch.widgets['case-files'].content.leadWidgetIds).toEqual(['case-document-verdicts-card'])
	})

	it('titles the Files card "Documenten bij dit verzoek" where documents are reviewed, and leaves it untitled elsewhere', () => {
		const files = simple.configPatch.widgets['case-files']
		expect(files.titleWhen).toEqual([
			{ when: { field: '@context.caseType.documentReview.enabled', op: 'eq', value: true }, text: 'Documents for this request' },
			{ text: '' },
		])
		expect(nl['Documents for this request']).toBe('Documenten bij dit verzoek')
	})

	it('draws the verdicts as a bar headed "4 van 7 beoordeeld"', () => {
		const bar = card('case-document-verdicts-card')
		expect(bar.type).toBe('stacked-bar')
		expect(bar.visibleWhen).toEqual(REVIEWED)
		expect(bar.content.endpointSource.url).toBe(VERDICTS)
		expect(routes).toContain("'url' => '/api/cases/{id}/review/verdicts'")
		expect(bar.content.groupsPath).toBe('counts')
		expect(bar.content.totalPath).toBe('documents')
		expect(bar.content.order.map((key) => nl[bar.content.labels[key]])).toEqual(['Openbaar', 'Deels openbaar', 'Niet openbaar'])
		expect(nl[bar.content.remainder.label]).toBe('Nog beoordelen')
		expect(nl[bar.content.heading].replace('{counted}', 4).replace('{total}', 7)).toBe('4 van 7 beoordeeld')
		expect(nl[bar.content.headingMeta].replace('{remaining}', 3)).toBe('Nog 3 te gaan voor het besluit')
		expect(en[bar.content.heading]).toBe(bar.content.heading)
	})

	it('lists the grounds cited so far with the documents citing them', () => {
		const grounds = card('case-refusal-grounds-card')
		expect(grounds.type).toBe('record-list')
		expect(grounds.visibleWhen).toEqual(REVIEWED)
		expect(nl[grounds.title]).toBe('Weigeringsgronden tot nu toe')
		expect(grounds.content.endpointSource).toEqual({ url: VERDICTS, responsePath: 'grounds' })
		expect(grounds.content.title).toBe('{label}')
		expect(grounds.content.meta).toBe('{documentList}')
		expect(nl[grounds.content.headerNote]).toBe('Gaan mee in het besluit')
		expect(grounds.content.headerSubtitle).toBeUndefined()
	})

	it('leads each ground with its code under a hairline and ends with the rule the board states', () => {
		const grounds = card('case-refusal-grounds-card')
		expect(grounds.content.pillAt).toBe('start')
		expect(grounds.content.ruleAbove).toBe(true)
		expect(nl[grounds.content.footnote]).toBe('Een document dat niet of deels openbaar is, heeft altijd ten minste één grond nodig.')
	})
})

describe('the Files card on a case type that reviews its documents', () => {
	it('says how to assess the documents, only where the case type reviews them', () => {
		const files = simple.configPatch.widgets['case-files']
		const [entry] = files.subtitle
		expect(entry.when).toEqual(REVIEWED)
		expect(nl[entry.text]).toBe('Per document beoordelen of het openbaar kan. Wat deels openbaar is, lakt u daarna.')
	})
})

describe('the verdicts summary in the files card (DqZaakDocumenten)', () => {
	it('is the compact box: thin bar, legend on one line, no inset of its own', () => {
		const summary = card('case-document-verdicts-card')
		expect(summary.content.compact).toBe(true)
		expect(summary.content.inset).not.toBe(true)
	})
})
