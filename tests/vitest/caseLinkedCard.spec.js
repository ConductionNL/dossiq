/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak draws what other apps hold for a case in ONE side card, "Gekoppeld":
 * the linked rows, then "Leges en betalingen, contract en projecten: niets
 * gekoppeld" with "Nieuw project" and "Openen in planninq". The case page
 * places nextcloud-vue's `linked` widget in its side column with the render
 * leaves of filinq, shillinq and planninq, and no longer places planninq's
 * projects as a widget of its own.
 *
 * @spec openspec/changes/case-linked-card/specs/case-linked-card/spec.md#requirement-the-case-page-lists-its-links-in-one-side-card
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function read(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, 'src', file), 'utf8'))
}
const simpleCase = read('menu-layout.simple.json').pages.find(
	(page) => page.id === 'CaseDetail',
).config
const fullCase = read('manifest.json').pages.find(
	(page) => page.id === 'CaseDetail',
).config

describe('the Linked side card on the case page', () => {
	const linked = simpleCase.sideColumn.find(
		(card) => typeof card === 'object' && card.id === 'side-linked',
	)

	it('is a linked widget in the side column, right after Handling, as DqZaak draws it', () => {
		expect(linked).toBeDefined()
		expect(linked.type).toBe('linked')
		expect(linked.title).toBe('Linked')
		expect(linked.visibleWhen).toBeUndefined()
		const ids = simpleCase.sideColumn.map((card) =>
			typeof card === 'object' ? card.id : card,
		)
		expect(ids.indexOf('side-linked')).toBe(ids.indexOf('side-handling') + 1)
	})

	it('lists the render leaves in the board order: documents, fees, contract, projects', () => {
		expect(linked.content.leaves).toEqual([
			'filinq-documents',
			'shillinq-payment-requests-panel',
			'shillinq-contracts-panel',
			'planninq-projects',
		])
	})

	it('no longer places the projects leaf as a widget or a grid cell', () => {
		expect(
			fullCase.widgets.some((widget) => widget.id === 'case-projects'),
		).toBe(false)
		expect(
			fullCase.layout.some((cell) => cell.widgetId === 'case-projects'),
		).toBe(false)
		expect(JSON.stringify(read('menu-layout.simple.json'))).not.toContain(
			'work-projects',
		)
	})
})
