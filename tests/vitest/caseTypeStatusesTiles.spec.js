/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeOverzicht "Statussen": the statuses in order as two columns of
 * framed tiles, each with its colour dot, its name and what it marks at the
 * end (Start, Term suspended, End), and a line under them saying where colour
 * and order apply.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const layout = readJson('src', 'menu-layout.simple.json')
const page = layout.pages.find((entry) => entry.id === 'CaseTypeDetail')
const statuses = page.configAppend.widgets.find((widget) => widget.id === 'case-type-statuses').content
const nl = readJson('l10n', 'nl.json').translations

describe('the Statuses card on the case type Overview', () => {
	it('draws the statuses as tiles in order', () => {
		expect(statuses).toMatchObject({ presentation: 'rows', tiles: true, sort: { field: 'order', dir: 'asc' } })
	})

	it('draws each status in its own colour', () => {
		expect(statuses.dot.field).toBe('colour')
		expect(statuses.dot.colorMap.teal).toBe('var(--nl-color-teal, #1d5e66)')
		expect(Object.keys(statuses.dot.colorMap)).toHaveLength(14)
	})

	it('names the start, a suspended term and the end at the tile\'s end', () => {
		expect(statuses.flags).toEqual([
			{ field: 'role', value: 'intake', label: 'Start' },
			{ field: 'waitingOn', value: 'applicant', label: 'Term suspended' },
			{ field: 'isFinal', label: 'End' },
		])
		expect(statuses.pill).toBeUndefined()
		statuses.flags.forEach((flag) => expect(nl[flag.label]).toBeTruthy())
	})

	it('says where colour and order apply', () => {
		expect(nl[statuses.footnote]).toBe('Kleur en volgorde gelden in lijsten, op het werkbord en in het portaal.')
	})
})
