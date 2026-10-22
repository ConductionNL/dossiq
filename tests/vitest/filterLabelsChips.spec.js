/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Active line names a filter as a person reads it, also one that came
 * from a link: "Status: lopend" on the cases list (board DqZaken), "Deadline:
 * 1 tot en met 31 oktober" on the task list (board DqTaken). Without
 * `filterLabels` the chips read "Is Final Status: false" and a raw due-date
 * key.
 *
 * @spec openspec/changes/index-filter-labels/specs/active-filter-line-board-look/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

/**
 * One page of the shipped manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	return manifest.pages.find((p) => p.id === id)
}

/**
 * The Dutch chip text for one filter value, as the Active line builds it.
 *
 * @param {object} labels The page's filterLabels.
 * @param {string} key The filter key.
 * @param {string} value The value as it sits in the address.
 * @return {string} The chip text.
 */
function dutchChip(labels, key, value) {
	const entry = labels[key]
	return `${nl[entry.label]}: ${nl[entry.values[value]]}`
}

describe('the cases list names the open-or-closed filter', () => {
	const labels = page('Cases').config.filterLabels

	it('reads Status: lopend for ?isFinalStatus=false', () => {
		expect(dutchChip(labels, 'isFinalStatus', 'false')).toBe('Status: lopend')
	})

	it('reads Status: afgerond for ?isFinalStatus=true', () => {
		expect(dutchChip(labels, 'isFinalStatus', 'true')).toBe('Status: afgerond')
	})
})

describe('the task list names its due-date range', () => {
	it('names dueAt Deadline, the field the sidebar declares as a date range', () => {
		const config = page('Tasks').config
		expect(nl[config.filterLabels.dueAt.label]).toBe('Deadline')
		expect(config.sidebar.fields.dueAt.inputControl).toBe('date-range')
	})
})
