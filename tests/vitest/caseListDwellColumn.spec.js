// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Days in status column and the Stuck lens on the Cases index.
 *
 * Every assertion here guards a way the column could ship dark or ship wrong
 * while reading green:
 *
 *  - the column is keyed on `currentStatusDwellDays`, the number the engine
 *    HOLDS on the case. Keyed on anything the process mining page computes it
 *    would have no column to order by, and a sortable header would send an
 *    `_order` the mapper answers by ignoring — which reads as a broken sort
 *    rather than as an absent one;
 *  - the widget name has to resolve in `src/services/cellWidgets.js`. A name
 *    that does not renders the raw value, so the breached chip would simply
 *    never appear and nothing would fail;
 *  - the Stuck lens narrows on `statusDwellBreached` and NOT on the deadline.
 *    Folding it into Overdue would bury exactly the case it exists to find:
 *    stuck for nine weeks inside a term that runs for six months;
 *  - an absent number renders an EMPTY cell, not a zero. Zero is a claim —
 *    the case entered its status today — and every case predating the change
 *    would otherwise sort beside the ones that did.
 *
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import DwellDaysCell from '../../src/components/cells/DwellDaysCell.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const cellWidgetsSource = fs.readFileSync(
	path.join(ROOT, 'src', 'services', 'cellWidgets.js'),
	'utf8',
)

/**
 * One page out of the manifest, by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = manifest.pages.find((p) => p.id === id)
	expect(found, `page ${id} is missing from the manifest`).toBeTruthy()
	return found
}

/**
 * The dwell column of the Cases index.
 *
 * @return {object} The column entry.
 */
function dwellColumn() {
	const found = page('Cases').config.columns.find(
		(c) => typeof c === 'object' && c.key === 'currentStatusDwellDays',
	)
	expect(found, 'the Cases index carries no Days in status column').toBeTruthy()
	return found
}

describe('the Days in status column', () => {
	it('is keyed on the number the case holds, so the server can sort it', () => {
		expect(dwellColumn().key).toBe('currentStatusDwellDays')
	})

	it('is sortable, which is the whole reason the number is held', () => {
		// Absent means sortable in CnDataTable, so either spelling passes;
		// an explicit false would not, and that is what this asserts.
		expect(dwellColumn().sortable).not.toBe(false)
	})

	it('renders through a cell widget that is actually registered', () => {
		const widget = dwellColumn().widget
		expect(widget).toBe('dwellDays')
		expect(cellWidgetsSource).toContain(`${widget}: DwellDaysCell`)
		expect(cellWidgetsSource).toContain('DwellDaysCell.vue')
	})
})

describe('the Stuck lens', () => {
	const chips = page('Cases').config.quickFilters
	const stuck = chips.find((c) => c.label === 'Stuck')

	it('is declared exactly once', () => {
		expect(chips.filter((c) => c.label === 'Stuck')).toHaveLength(1)
	})

	it('narrows on the held breach flag and never on the deadline', () => {
		expect(stuck.filter).toEqual({
			isFinalStatus: false,
			statusDwellBreached: true,
		})
		// A dwell breach is not a term breach. If this lens ever carried a
		// deadline condition it would hide the case it exists to find.
		expect(Object.keys(stuck.filter).join(' ')).not.toContain('deadline')
	})

	it('is not the Overdue lens under another name', () => {
		const overdue = chips.find((c) => c.label === 'Overdue')
		expect(overdue.filter).not.toEqual(stuck.filter)
	})
})

describe('the Days in status cell', () => {
	it('renders the number in working days', () => {
		const wrapper = mount(DwellDaysCell, { props: { value: 12, row: {} } })

		expect(wrapper.text()).toContain('12')
		expect(wrapper.text()).toContain('working days')
	})

	it('carries the breached state in words as well as in colour', () => {
		const wrapper = mount(DwellDaysCell, {
			props: { value: 25, row: { statusDwellBreached: true } },
		})

		expect(wrapper.find('[data-testid="dwell-days"]').classes()).toContain(
			'is-breached',
		)
		// WCAG 2.2 SC 1.4.1: colour is not the only carrier of the state.
		expect(wrapper.text()).toContain('stuck')
	})

	it('says nothing about a case that is inside its maximum', () => {
		const wrapper = mount(DwellDaysCell, {
			props: { value: 3, row: { statusDwellBreached: false } },
		})

		expect(wrapper.find('[data-testid="dwell-days"]').classes()).not.toContain(
			'is-breached',
		)
		expect(wrapper.text()).not.toContain('stuck')
	})

	it('renders an empty cell rather than a zero for a case that holds no number', () => {
		const wrapper = mount(DwellDaysCell, { props: { value: null, row: {} } })

		expect(wrapper.find('[data-testid="dwell-days"]').exists()).toBe(false)
		expect(wrapper.text()).toBe('')
	})
})
