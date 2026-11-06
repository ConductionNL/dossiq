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
 * @spec openspec/specs/case-linked-card/spec.md#requirement-the-case-page-lists-its-links-in-one-side-card
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

	it('lists the leaves in the board order: documents, the pipelinq question, fees, contract, projects', () => {
		const ids = linked.content.leaves.map((leaf) =>
			typeof leaf === 'object' ? leaf.id : leaf,
		)
		expect(ids).toEqual([
			'filinq-documents',
			'pipelinq-requests',
			'shillinq-financial-documents-panel',
			'shillinq-contracts-panel',
			'planninq-projects',
		])
	})

	it('names the finance leaf "fees and payments" in its own words, as the board writes the line', () => {
		for (const leaves of [linked.content.leaves, fullCase.widgets.find((widget) => widget.id === 'case-linked').content.leaves]) {
			expect(leaves).toContainEqual({ id: 'shillinq-financial-documents-panel', noun: 'fees and payments' })
		}
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
		expect(nl['fees and payments']).toBe('leges en betalingen')
	})

	// The noun stays blank: the board's nothing-linked line names only leges, contract and projects.
	it('reads "Vraag in pipelinq" from the pipelinq request that points at this case, as configuration', () => {
		const requests = linked.content.leaves.find(
			(leaf) => typeof leaf === 'object' && leaf.id === 'pipelinq-requests',
		)
		expect(requests).toMatchObject({
			register: 'pipelinq',
			schema: 'ticket',
			filter: { caseReference: '@objectId', ticketType: 'request' },
			requiredApp: 'pipelinq',
			noun: '',
			label: 'Question in pipelinq',
			href: '/apps/pipelinq/tickets/{id}',
		})
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

describe('the Linked card in the full profile, which has no side column', () => {
	it('sits in the grid cell the Projects widget had, with the same leaves', () => {
		const widget = fullCase.widgets.find((w) => w.id === 'case-linked')
		expect(widget.type).toBe('linked')
		expect(widget.content.leaves).toEqual(
			simpleCase.sideColumn.find(
				(card) => typeof card === 'object' && card.id === 'side-linked',
			).content.leaves,
		)
		const cell = fullCase.layout.find((c) => c.widgetId === 'case-linked')
		expect(cell).toMatchObject({
			id: 'full-linked',
			gridX: 0,
			gridY: 27,
			gridWidth: 6,
		})
	})

	it('is dropped from the simple profile, whose side column carries it', () => {
		const patch = JSON.stringify(read('menu-layout.simple.json'))
		expect(patch).toContain('"full-linked":null')
	})
})

describe('the money placements mount the leaves shillinq registers', () => {
	it('tells the financial documents panel what a case shows and offers', () => {
		const widget = fullCase.widgets.find((w) => w.id === 'case-payment-requests')
		expect(widget.props).toEqual({
			kinds: ['quote', 'invoice', 'credit-note', 'payment-request', 'payout'],
			actions: ['issue-invoice', 'new-quote', 'raise-payout', 'request-payment', 'credit-invoice', 'send-link', 'record-payment'],
			objectType: 'case',
		})
	})

	it.each([
		['case-payment-requests', 'shillinq-financial-documents-panel'],
		['case-contract', 'shillinq-contracts-panel'],
	])('%s places %s', (id, integrationId) => {
		expect(fullCase.widgets.find((w) => w.id === id).integrationId).toBe(
			integrationId,
		)
	})
})
