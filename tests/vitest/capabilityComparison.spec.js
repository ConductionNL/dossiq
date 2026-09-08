/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Guards the capability comparison shown on the Features & roadmap page.
 *
 * The data file is generated ONCE, offline, from a private audit repo, so CI
 * cannot regenerate it and diff the result. These assertions are the only
 * thing standing between a hand-edit and a page that quietly claims something
 * the audit never said.
 */

import { describe, expect, it } from 'vitest'
import data from '../../src/data/capabilityComparison.json'
import {
	formatComparedOn,
	groupByArea,
	labelFor,
	overallTallies,
	RATINGS,
	tally,
} from '../../src/utils/capabilityComparison.js'

// The audit's own totals, from concurrentie-analyse
// procest/_round2/compare/M1-functionality.md "Tally per area". Hard-coded on
// purpose: if a row is edited, one of these fails and names the system whose
// score moved.
const AUDIT_TOTALS = {
	dossiq: { yes: 80, partial: 85, no: 41 },
	opencase: { yes: 62, partial: 46, no: 98 },
	gzac: { yes: 86, partial: 55, no: 65 },
	zaaksysteem: { yes: 136, partial: 40, no: 30 },
}

// Two labels are identical in English and Dutch because the Dutch IS the
// English: `StUF (BG, ZKN, DCR)` is a Dutch standard's own name, and `Intake`
// is the same word in both. Everything else must genuinely differ, which is
// how a row that was added without a translation gets caught.
const IDENTICAL_BY_DESIGN = new Set(['12.4'])

describe('capabilityComparison data', () => {
	it('carries the 206 rows and 13 areas the audit produced', () => {
		expect(data.capabilities).toHaveLength(206)
		expect(data.areas).toHaveLength(13)
		expect(data.systems).toHaveLength(4)
	})

	it('gives every row a unique id', () => {
		const ids = data.capabilities.map((c) => c.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('files every row under a declared area', () => {
		const areas = new Set(data.areas.map((a) => a.key))
		const orphans = data.capabilities.filter((c) => !areas.has(c.area))
		expect(orphans.map((c) => c.id)).toEqual([])
	})

	it('rates every system on every row with a known rating', () => {
		const bad = []
		for (const row of data.capabilities) {
			for (const system of data.systems) {
				if (!RATINGS.includes(row[system.key])) {
					bad.push(`${row.id}/${system.key}=${row[system.key]}`)
				}
			}
		}
		expect(bad).toEqual([])
	})

	it('reproduces the audit tallies for all four systems', () => {
		const totals = overallTallies(data)
		for (const [system, expected] of Object.entries(AUDIT_TOTALS)) {
			expect(
				{
					yes: totals[system].yes,
					partial: totals[system].partial,
					no: totals[system].no,
				},
				system,
			).toEqual(expected)
			expect(totals[system].total, system).toBe(206)
		}
	})

	it('translates every capability and every area into Dutch', () => {
		const missing = data.capabilities.filter(
			(c) => !c.name_nl || c.name_nl.trim() === '',
		)
		expect(missing.map((c) => c.id)).toEqual([])

		const untranslated = data.capabilities.filter(
			(c) => c.name_nl === c.name && !IDENTICAL_BY_DESIGN.has(c.id),
		)
		expect(untranslated.map((c) => c.id)).toEqual([])

		const areasMissing = data.areas.filter(
			(a) => !a.name_nl || a.name_nl.trim() === '',
		)
		expect(areasMissing.map((a) => a.key)).toEqual([])
	})

	it('records when the comparison was made', () => {
		expect(data.comparedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})
})

describe('labelFor', () => {
	const entry = {
		name: 'Case tags or labels',
		name_nl: 'Labels of tags op een zaak',
	}

	it('returns Dutch for a Dutch locale', () => {
		expect(labelFor(entry, 'nl')).toBe('Labels of tags op een zaak')
		expect(labelFor(entry, 'nl_NL')).toBe('Labels of tags op een zaak')
	})

	it('returns English for anything else', () => {
		expect(labelFor(entry, 'en')).toBe('Case tags or labels')
		expect(labelFor(entry, 'de')).toBe('Case tags or labels')
	})

	it('falls back to English when the Dutch is missing or blank', () => {
		expect(labelFor({ name: 'A', name_nl: '' }, 'nl')).toBe('A')
		expect(labelFor({ name: 'A' }, 'nl')).toBe('A')
	})
})

describe('tally', () => {
	it('counts an unknown rating instead of dropping it', () => {
		const counts = tally([{ x: 'yes' }, { x: 'wat' }, { x: 'no' }], 'x')
		expect(counts).toEqual({ yes: 1, partial: 0, no: 1, unknown: 1, total: 3 })
	})
})

describe('groupByArea', () => {
	it('keeps the audit ordering, Intake first and Access and privacy last', () => {
		const groups = groupByArea(data, 'en')
		expect(groups[0].label).toBe('Intake')
		expect(groups[groups.length - 1].label).toBe('Access and privacy')
	})

	it('places every row in exactly one area group', () => {
		const groups = groupByArea(data, 'en')
		const total = groups.reduce((n, g) => n + g.capabilities.length, 0)
		expect(total).toBe(206)
	})

	it('labels the groups and their rows in Dutch for a Dutch locale', () => {
		const groups = groupByArea(data, 'nl')
		const deadlines = groups.find((g) => g.key === 'deadlines')
		expect(deadlines.label).toBe('Termijnen')
		expect(deadlines.capabilities.every((c) => c.label === c.name_nl)).toBe(true)
	})

	it('tallies each area per system', () => {
		const groups = groupByArea(data, 'en')
		const intake = groups.find((g) => g.key === 'intake')
		expect(intake.capabilities).toHaveLength(13)
		expect(intake.tallies.dossiq.total).toBe(13)
	})
})

describe('formatComparedOn', () => {
	it('renders the date in the reader-s language', () => {
		expect(formatComparedOn('2026-09-07', 'en')).toContain('2026')
		expect(formatComparedOn('2026-09-07', 'nl')).toContain('september')
	})

	it('returns the raw value when it is not a date', () => {
		expect(formatComparedOn('not-a-date', 'en')).toBe('not-a-date')
		expect(formatComparedOn('', 'en')).toBe('')
	})
})
