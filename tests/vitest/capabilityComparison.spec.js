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
	behindEveryRival,
	formatComparedOn,
	groupByArea,
	labelFor,
	overallTallies,
	RATING_COLUMNS,
	RATINGS,
	tally,
} from '../../src/utils/capabilityComparison.js'

// The audit's own totals. Hard-coded on purpose: if a row is edited, one of
// these fails and names the system whose score moved.
//
// Round 3 added 19 rows on 2026-09-09. It read GLPI and Zammad, not the other
// products in this table, so each of them carries `unknown` on all 19 and their
// yes/partial/no counts did not move. Ours did, downward: 84/87/35 over 206
// became 87/92/46 over 225. That is the round working, not the product
// regressing. The rows are things the round 2 list never thought to ask.
//
// Dimpact ZAC joined as a fifth column on 2026-09-10, from a round 3 reading
// of infonl/dimpact-zaakafhandelcomponent taken on 2026-09-08. That reading
// covered the 206 rows round 2 produced, so ZAC is `unknown` on the same 19
// rows the other three are: it was never read against them either.
const AUDIT_TOTALS = {
	dossiq: { yes: 87, partial: 92, no: 46, unknown: 0 },
	opencase: { yes: 62, partial: 46, no: 98, unknown: 19 },
	gzac: { yes: 86, partial: 55, no: 65, unknown: 19 },
	zaaksysteem: { yes: 136, partial: 40, no: 30, unknown: 19 },
	zac: { yes: 69, partial: 61, no: 76, unknown: 19 },
}

const ROW_COUNT = 225

// The day the first four columns were read. Pinned, because the honest way to
// add a fifth column read on another day is a second date, never a quiet nudge
// of this one: moving it would relabel four readings that never happened again.
const COMPARED_ON = '2026-09-07'

// Our own column moved on 2026-09-08: six rows the audit read as `no` on
// 2026-09-07 had been built by the next day. 80/85/41 became 84/87/35, and the
// other three columns did not move, because re-rating someone else's product
// without re-reading it is the dishonesty this page exists to avoid. Every
// move is listed in the data file's `_rerated`, which the guard below pins to
// the ratings themselves so a note cannot outlive the score it explains.
const RERATED_IDS = ['1.8', '2.8', '2.9', '4.9', '5.5', '11.23']

// Rows round 3 added to the list on 2026-09-09, from GLPI 11.0.8 and Zammad
// 7.1.3 driven locally. They are logged in `_rerated` with `cause: 'added'`
// and a null `from`, because there was no previous rating to move.
const ADDED_IDS = [
	'2.23',
	'2.24',
	'2.25',
	'2.26',
	'2.27',
	'3.20',
	'6.15',
	'6.16',
	'8.11',
	'8.12',
	'8.13',
	'8.14',
	'8.15',
	'9.13',
	'10.11',
	'11.25',
	'11.26',
	'13.17',
	'13.18',
]

// The rows where every rival has the capability and we do not. Pinned
// rather than asserted empty, because it is NOT empty and a plan that said so
// was wrong: most of these seven are stale in the same way the six above were,
// and they belong to the partial column, which phase 3 owns and this change
// does not touch. The list shrinks as phase 3 re-rates, and this test is what
// makes each of those moves visible instead of a page quietly claiming a clean
// sheet. 2.4, a one-click claim on a case, is the one row here that is
// certainly still true.
//
// It was seven until Dimpact ZAC became the fourth rival. 2.1, a case number
// from a mask or sequence, left the list because ZAC does not have it either:
// ZAC never sets `identificatie` and takes the number Open Zaak hands back,
// with no mask, sequence or prefix configurable anywhere. A rival column can
// only ever shorten this list, never lengthen it, because every row on it has
// to be `yes` for every rival. So a fifth column cannot turn a boast into an
// overclaim here. It can only retire a row we were right to admit.
const BEHIND_EVERY_RIVAL = ['2.4', '4.16', '4.22', '9.1', '11.10', '12.7']

// Two labels are identical in English and Dutch because the Dutch IS the
// English: `StUF (BG, ZKN, DCR)` is a Dutch standard's own name, and `Intake`
// is the same word in both. Everything else must genuinely differ, which is
// how a row that was added without a translation gets caught.
const IDENTICAL_BY_DESIGN = new Set(['12.4'])

describe('capabilityComparison data', () => {
	it('carries the 225 rows and 13 areas the audit produced', () => {
		expect(data.capabilities).toHaveLength(ROW_COUNT)
		expect(data.areas).toHaveLength(13)
		expect(data.systems).toHaveLength(5)
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
				if (!RATING_COLUMNS.includes(row[system.key])) {
					bad.push(`${row.id}/${system.key}=${row[system.key]}`)
				}
			}
		}
		expect(bad).toEqual([])
	})

	it('never leaves our own column unrated', () => {
		// We can always read our own code. An `unknown` in the dossiq column
		// is not honesty about someone else's product, it is a row nobody
		// finished, and it would understate our score for free.
		const unrated = data.capabilities.filter((c) => !RATINGS.includes(c.dossiq))
		expect(unrated.map((c) => c.id)).toEqual([])
	})

	it('marks an unrated competitor cell as a row a later round added', () => {
		// The only honest reason a competitor cell is empty is that the row
		// was added after that product was read. Any other `unknown` is a gap
		// in the audit pretending to be a disclosure.
		const rivals = data.systems.filter((s) => !s.isSelf).map((s) => s.key)
		const wrong = data.capabilities.filter(
			(c) => rivals.some((k) => c[k] === 'unknown') && !c.addedOn,
		)
		expect(wrong.map((c) => c.id)).toEqual([])
	})

	it('leaves every rival unrated on a row added after they were read', () => {
		// The mirror of the test above. A row added without re-reading the
		// other products cannot carry a rating for any of them: a guess in a
		// competitor's column is the error this page exists to avoid.
		const rivals = data.systems.filter((s) => !s.isSelf).map((s) => s.key)
		const guessed = data.capabilities.filter(
			(c) => c.addedOn && rivals.some((k) => c[k] !== 'unknown'),
		)
		expect(guessed.map((c) => c.id)).toEqual([])
	})

	it('dates every added row on the day the rows were added', () => {
		const added = data.capabilities.filter((c) => c.addedOn)
		expect(added.map((c) => c.id).sort()).toEqual([...ADDED_IDS].sort())
		expect(data.rowsAddedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		for (const row of added) {
			expect(row.addedOn, row.id).toBe(data.rowsAddedOn)
			expect(row.addedOn >= data.comparedOn, row.id).toBe(true)
		}
	})

	it('reproduces the audit tallies for every system', () => {
		const totals = overallTallies(data)
		for (const [system, expected] of Object.entries(AUDIT_TOTALS)) {
			expect(
				{
					yes: totals[system].yes,
					partial: totals[system].partial,
					no: totals[system].no,
					unknown: totals[system].unknown,
				},
				system,
			).toEqual(expected)
			expect(totals[system].total, system).toBe(ROW_COUNT)
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
		expect(data.comparedOn).toBe(COMPARED_ON)
	})

	it('dates a column added later on its own day, and leaves comparedOn alone', () => {
		// The page renders one sentence about when the systems were read. A
		// column read on a different day cannot be folded into it, because
		// moving `comparedOn` to cover the newcomer would make that sentence
		// false for every column it already covered. So a late column carries
		// its own `readOn`, and `comparedOn` is pinned above.
		const late = data.systems.filter((s) => s.readOn)
		expect(late.map((s) => s.key)).toEqual(['zac'])
		for (const system of late) {
			expect(system.readOn, system.key).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(system.readOn > data.comparedOn, system.key).toBe(true)
			expect(system.columnAddedOn, system.key).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(system.columnAddedOn >= system.readOn, system.key).toBe(true)
			expect(system.isSelf, system.key).toBeUndefined()
		}
	})

	it('declares the register a column that owns no data defers to', () => {
		// A product whose record and retention live somewhere else loses rows
		// to its architecture on a list written in our shape, and its column
		// reads low for a reason that is not about the product. The page says
		// so beside the column, and it says so because the data declares it:
		// dropping the flag silently drops the caveat with it.
		const deferring = data.systems.filter((s) => s.ownsNoData)
		expect(deferring.map((s) => s.key)).toEqual(['zac'])
		for (const system of deferring) {
			expect(typeof system.ownsNoData, system.key).toBe('string')
			expect(system.ownsNoData.trim().length, system.key).toBeGreaterThan(0)
		}
	})

	it('records when our own column was last corrected', () => {
		expect(data.reratedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(data.reratedOn >= data.comparedOn).toBe(true)
	})

	it('explains every correction, and corrects only our own column', () => {
		expect(data._rerated.map((r) => r.id).sort()).toEqual(
			[...RERATED_IDS, ...ADDED_IDS].sort(),
		)
		for (const entry of data._rerated) {
			expect(entry.from, entry.id).not.toBe(entry.to)
			expect(RATINGS, entry.id).toContain(entry.to)
			expect(entry.on, entry.id).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(entry.reason.length, entry.id).toBeGreaterThan(20)
			expect(['built', 'added'], entry.id).toContain(entry.cause)
		}
	})

	it('logs an added row as an addition and not as a correction', () => {
		// A row that never had a rating cannot have been corrected. Recording
		// one as `built` would tell a reader we shipped something, when what
		// happened is that we started asking a question we had ducked.
		const added = data._rerated.filter((e) => e.cause === 'added')
		expect(added.map((e) => e.id).sort()).toEqual([...ADDED_IDS].sort())
		for (const entry of added) {
			expect(entry.from, entry.id).toBeNull()
			expect(entry.on, entry.id).toBe(data.rowsAddedOn)
		}
	})

	it('keeps every correction note pinned to the rating it explains', () => {
		// The failure this catches: a row is edited again later and the note
		// beside it goes on describing the previous value. A note that
		// disagrees with its own row is worse than no note.
		const byId = new Map(data.capabilities.map((c) => [c.id, c]))
		const drifted = data._rerated.filter(
			(entry) => byId.get(entry.id)?.dossiq !== entry.to,
		)
		expect(drifted.map((entry) => entry.id)).toEqual([])
	})
})

/**
 * A one-row comparison where every rival has the capability and we do not,
 * with the given cells overridden.
 *
 * Built from `data.systems` rather than written out. These fixtures used to
 * name the four columns by hand, and adding a fifth left its cell undefined:
 * every row then failed the `every rival is yes` test for a reason the test
 * never meant to check, and the two that assert an empty result went on
 * passing while checking nothing.
 *
 * @param {object} overrides Cells to replace, keyed by system.
 * @return {object} A comparison shaped like the data file.
 */
function oneRow(overrides) {
	const row = { id: 'x' }
	for (const system of data.systems) {
		row[system.key] = system.isSelf ? 'no' : 'yes'
	}
	return { systems: data.systems, capabilities: [{ ...row, ...overrides }] }
}

describe('behindEveryRival', () => {
	it('names the rows where every rival has it and we do not', () => {
		expect(behindEveryRival(data).map((row) => row.id)).toEqual(
			BEHIND_EVERY_RIVAL,
		)
	})

	it('counts a partial on our side as behind', () => {
		expect(
			behindEveryRival(oneRow({ dossiq: 'partial' })).map((row) => row.id),
		).toEqual(['x'])
	})

	it('does not count a row a rival was never rated on', () => {
		// A row added by a later round leaves every competitor cell `unknown`.
		// Counting one of those as agreement would let us claim four teams
		// shipped something we did not, on no evidence at all.
		expect(behindEveryRival(oneRow({ gzac: 'unknown' }))).toEqual([])
	})

	it('does not count a row one rival merely half has', () => {
		expect(behindEveryRival(oneRow({ gzac: 'partial' }))).toEqual([])
	})

	it('drops a row as soon as one more rival lacks it', () => {
		// The direction a new column can move this list. Adding a rival can
		// only shorten it, never lengthen it, because every row has to be
		// `yes` for every rival. That is why a fifth column cannot turn this
		// page's admission into an overclaim, and it is worth a test rather
		// than a comment.
		expect(behindEveryRival(oneRow({ zac: 'partial' }))).toEqual([])
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
		expect(total).toBe(ROW_COUNT)
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
