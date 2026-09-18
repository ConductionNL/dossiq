/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact 360 answers all three questions a caller asks.
 *
 * `ContactDetail` and `OrganisationDetail` carried a card, a cases list and a
 * contact-moments list. Cases and communication were there; documents were
 * not, on either page, so a KCC agent with a caller on the line could see
 * which cases that person has and what was said to them, and not the letter
 * the gemeente sent them last week.
 *
 * WHY `dispatch` AND NOT `informatieobject`. A document names its
 * correspondents in two fields, `sender` (one party) and `recipients` (an
 * array). An object-list `filter` is a MAP, so every entry NARROWS: there is
 * no way to ask for a row matching either field. `dispatch` is the join
 * `document-correspondents` wrote for this question, one row per document per
 * party per role carrying the case, so one equality filter is the whole
 * answer and `relationshipType` is the direction.
 *
 * @spec openspec/changes/the-contact-360-shows-documents/specs/kcc-klantcontact-integratie/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const PAGES = ['ContactDetail', 'OrganisationDetail']

/**
 * One page of the manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

/**
 * The documents widget of one page.
 *
 * @param {string} id The page id.
 * @return {object|undefined} The widget entry.
 */
function documentsWidget(id) {
	return page(id).config.widgets.find((w) => w.id === 'contact-documents')
}

describe('the contact 360 shows documents', () => {
	it.each(PAGES)('%s declares a documents widget', (id) => {
		const widget = documentsWidget(id)
		expect(widget).toBeDefined()
		expect(widget.type).toBe('object-list')
		expect(widget.content.register).toBe('dossiq')
		expect(widget.content.schema).toBe('dispatch')
	})

	it.each(PAGES)('%s scopes the list to this contact alone', (id) => {
		// The whole point of the `dispatch` join: ONE equality filter answers
		// "sent by or sent to this contact". A filter that named a document
		// field instead would need an OR the endpoint does not have.
		expect(documentsWidget(id).content.filter).toEqual({
			involvedParty: '@objectId',
		})
	})

	it.each(PAGES)('%s carries the direction on every row', (id) => {
		const columns = documentsWidget(id).content.columns
		const direction = columns.find((c) => c.key === 'relationshipType')
		expect(direction).toBeDefined()
		// The raw codes are what the schema stores; a row reading `afzender`
		// tells a caller nothing, so the column carries its own label map.
		expect(direction.enumLabels).toEqual({
			afzender: 'Sent by this contact',
			geadresseerde: 'Sent to this contact',
		})
	})

	it.each(PAGES)('%s inlines the document and the case it is on', (id) => {
		const content = documentsWidget(id).content
		expect(content.extend).toEqual(['document', 'case'])
		const keys = content.columns.map((c) => c.key)
		expect(keys).toContain('document.title')
		expect(keys).toContain('case.identifier')
	})

	it.each(PAGES)('%s deep links through a handler, never a rowRoute', (id) => {
		const content = documentsWidget(id).content
		// 🔴 `rowRoute` PUSHES THE ROW'S OWN ID. Here the row is a dispatch and
		// the destination is its case, so a declared route would open a case
		// page for a uuid no case has, which reads as a deleted case rather
		// than as a bug.
		expect(content.rowRoute).toBeUndefined()
		expect(content.rowActions).toHaveLength(1)
		expect(content.rowActions[0]).toMatchObject({
			id: 'open-case',
			type: 'handler',
			handler: 'openCaseOfDocument',
		})
	})

	it.each(PAGES)('%s says something in words when there is nothing', (id) => {
		const content = documentsWidget(id).content
		expect(content.emptyText).toBe(
			'No letters or documents on file for this contact yet',
		)
		// A listing that FAILED draws its own line above the empty state and
		// never both, so the two are never confused. The sentence is declared
		// here rather than left to the library's generic one.
		expect(content.errorText).toBe(
			'These documents could not be loaded. Try again.',
		)
	})

	it.each(PAGES)('%s caps the list so a partial read says so', (id) => {
		// Whether the objects endpoint counts what the reader may not read or
		// drops it is openregister's answer and is unmeasured here. What this
		// page controls is that a capped list is not presented as the whole
		// answer.
		expect(documentsWidget(id).content.limit).toBe(25)
	})

	it.each(PAGES)('%s leaves no gap in the layout under it', (id) => {
		const layout = page(id).config.layout
		const row = layout.find((l) => l.widgetId === 'contact-documents')
		expect(row).toBeDefined()

		const moments = layout.find((l) => l.widgetId === 'contact-moments')
		expect(row.gridY).toBe(moments.gridY + moments.gridHeight)
		expect(row.gridWidth).toBe(12)
	})

	it('places the widget on both pages and nowhere else', () => {
		// The count guard: a walk that matched nothing would satisfy every
		// `it.each` above by running zero assertions per page.
		const placements = []
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
				return
			}
			if (node === null || typeof node !== 'object') {
				return
			}
			if (node.id === 'contact-documents' && node.type === 'object-list') {
				placements.push(node)
			}
			Object.values(node).forEach(walk)
		}
		walk(manifest)
		expect(placements).toHaveLength(2)
	})
})

describe('openCaseOfDocument', () => {
	let assign

	beforeEach(() => {
		assign = vi.fn()
		vi.stubGlobal('window', { location: { assign } })
	})

	// The `/index.php` prefix is the @nextcloud/router STUB's, mirroring
	// generateUrl() on a default web root; what is asserted here is the tail.
	it('opens the case a dispatch row names as a bare uuid', async () => {
		const { openCaseOfDocument } = await import(
			'../../src/utils/contactDocuments.js'
		)
		openCaseOfDocument({ item: { id: 'dispatch-1', case: 'case-9' } })

		// The CASE, not the row: `dispatch-1` in this url would be the bug the
		// handler exists to prevent.
		expect(assign).toHaveBeenCalledWith('/index.php/apps/dossiq/cases/case-9')
	})

	it('reads the case when extend inlined it as an object', async () => {
		const { openCaseOfDocument } = await import(
			'../../src/utils/contactDocuments.js'
		)
		openCaseOfDocument({ id: 'dispatch-2', case: { id: 'case-9' } })

		expect(assign).toHaveBeenCalledWith('/index.php/apps/dossiq/cases/case-9')
	})

	it('goes nowhere when the row names no case', async () => {
		const { openCaseOfDocument } = await import(
			'../../src/utils/contactDocuments.js'
		)
		openCaseOfDocument({ item: { id: 'dispatch-3' } })

		expect(assign).not.toHaveBeenCalled()
	})
})
