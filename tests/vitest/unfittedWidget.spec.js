/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A list stacked in a tab renders every row it fetched.
 *
 * An object list fits its visible rows to the host grid cell, measured from
 * where its table starts. That is right for a list that owns its cell and wrong
 * for one stacked below another section: on a live case page the Objects list,
 * third section of Related, began 584px down a 696px cell, the budget floored to
 * one row, and one of the two objects the server returned did not render.
 * `case-objects.spec.ts` caught it (expected 2 rows, received 1).
 *
 * CaseSectionsWidget turns the budget off for every section it stacks. These
 * tests hold that in place, including against the real manifest, so a list
 * moved into a stacked tab later is covered without its author knowing to ask.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { unfittedWidget } from '../../src/components/case/unfittedWidget.js'

const ROOT = path.resolve(__dirname, '../..')

describe('unfittedWidget', () => {
	it('switches the cell budget off for a section that says nothing about it', () => {
		const widget = {
			id: 'case-objects',
			type: 'object-list',
			content: { schema: 'caseObject', limit: 25 },
		}
		const out = unfittedWidget(widget)
		expect(out.content.fit).toBe(false)
		// Everything else rides through: the list still fetches what it fetched.
		expect(out.content.schema).toBe('caseObject')
		expect(out.content.limit).toBe(25)
		expect(out.id).toBe('case-objects')
	})

	it('keeps a fit the widget already declares, true or false', () => {
		for (const fit of [true, false]) {
			const widget = { type: 'object-list', content: { fit } }
			expect(unfittedWidget(widget)).toBe(widget)
		}
	})

	it('supplies a content block to a widget that has none', () => {
		expect(unfittedWidget({ type: 'object-list' }).content).toEqual({
			fit: false,
		})
	})

	it('never mutates the manifest definition it was handed', () => {
		// The same definition is read again on every render. Writing `fit` into
		// it would leak the default into the manifest object for good, and the
		// "already declares a fit" branch would then see its own write.
		const widget = { type: 'object-list', content: { limit: 5 } }
		const snapshot = JSON.parse(JSON.stringify(widget))
		unfittedWidget(widget)
		expect(widget).toEqual(snapshot)
	})
})

describe('the case page', () => {
	it('stacks no object list that would still clip', () => {
		// Asserted against the real manifest, not a fixture: every object list
		// that is a section of a stacked tab must reach the renderer with the
		// budget off. Today that is Sub-cases and Objects under Related.
		const manifest = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'CaseDetail')
		const stacked = page.config.widgets
			.filter((w) => w.type === 'case-sections')
			.flatMap((w) => w.content.sections.map((s) => s.widget))
			.filter((w) => w.type === 'object-list')

		expect(stacked.length).toBeGreaterThan(0)
		for (const widget of stacked) {
			expect(
				unfittedWidget(widget).content.fit,
				`${widget.id} would still clip`,
			).toBe(false)
		}
	})
})
