/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Guards the capability comparison shown on the Features & roadmap page.
 *
 * The data file is RE-ISSUED from the parity corpus in
 * `ConductionNL/market-intelligence` by `scripts/sync-capability-comparison.mjs`.
 * CI has no checkout of that repo, so it cannot re-run the script and diff the
 * result. What it can do, and what the first test below does, is compare the
 * data file's id set against `capabilityComparison.corpus-ids.json`, which the
 * same script writes from the same source in the same run.
 *
 * That test exists because the rule it enforces was already written, already
 * correct, and was broken anyway. On 2026-09-10 dossiq#2314 hand-added 104 rows
 * here that no corpus artefact held, under ids the corpus used for other
 * questions. 42 ids collided, five discovery lanes then cited 39 of them as
 * existing rows, and eleven research candidates were withdrawn against rows
 * that did not exist. Nothing failed, because nothing checked. The
 * reconciliation is `procest/_round4/compare/dossiq-matrix-drift.md` in the
 * corpus repo and the decision is D1.
 */

import { describe, expect, it } from 'vitest'
import corpusIds from '../../src/data/capabilityComparison.corpus-ids.json'
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

// The corpus's own totals over the 225 ROWS. Hard-coded on purpose: if a row
// is edited, one of these fails and names the system whose score moved.
//
// These count `capabilities` and nothing else. `pending` is a second list with
// one rated column out of five, and folding it in would move every score
// against cells nobody has filled.
//
// Round 3 added 19 rows on 2026-09-09. It read GLPI and Zammad, not the other
// products in this table, so each of them carries `unknown` on all 19 and their
// yes/partial/no counts did not move. Ours did.
//
// Dimpact ZAC joined as a fifth column on 2026-09-10, from a round 3 reading
// of infonl/dimpact-zaakafhandelcomponent taken on 2026-09-08. That reading
// covered the 206 rows round 2 produced, so ZAC is `unknown` on the same 19
// rows the other three are: it was never read against them either.
//
// Our own column moved again on 2026-09-14, from the corpus's case-type depth
// study: 2.3, 2.7 and 3.3 from `partial` to `yes` and 11.2 from `yes` to `no`.
// 87/92/46 became 89/89/47. Every move is in `_rerated` with its evidence.
//
// The 104 rows this file carried between 2026-09-10 and 2026-09-14 are gone
// from `capabilities` and are not a regression: they were never rows. They are
// 98 of the 146 proposals in `pending`, under the ids the corpus issued them.
//
// Our column moved again on 2026-09-22, and by more than any reading before it.
// Thirty-five rows: sixteen the build wave closed on 2026-09-20, sixteen the
// 2026-09-19 gap scan found we already had, and three the corpus had corrected
// while this file still carried the old value. 89/89/47 became 121/69/35. The
// other four columns did not move, for the same reason they never do.
const AUDIT_TOTALS = {
	dossiq: { yes: 121, partial: 69, no: 35, unknown: 0 },
	opencase: { yes: 62, partial: 46, no: 98, unknown: 19 },
	gzac: { yes: 86, partial: 55, no: 65, unknown: 19 },
	zaaksysteem: { yes: 136, partial: 40, no: 30, unknown: 19 },
	zac: { yes: 69, partial: 61, no: 76, unknown: 19 },
}

const ROW_COUNT = 225

// The proposals. Not rows, not scored, not in any total on the page.
const PENDING_COUNT = 146

// The proposals whose own column we have not filled either. See the test.
const OURS_UNMEASURED = ['Q13.25']

// Thirteen areas came from the audit. Round 4 added four more, because four of
// its rows had nowhere to go: a case plan of arranged services, money on the
// case, offline field work, and one instance serving several organisations. An
// area with no home for a capability is the instrument failing quietly, so the
// areas grew rather than the rows being filed somewhere approximate.
const AREA_COUNT = 17

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

// Our column moved again on 2026-09-14, and for a different reason: the corpus
// re-read our case-type configuration end to end and three ratings were too
// harsh while one was too kind. `cause: 'corrected'` rather than `'built'`,
// because nothing was shipped between the two readings. The first reading was
// wrong, and a page about honesty has to be able to say which of the two it is.
//
// The 2026-09-22 re-rating adds thirty-five more, and 11.2 appears twice: it
// went yes to no on 2026-09-14 when the export turned out to be a placeholder,
// and no to yes on 2026-09-22 when the placeholder was replaced. A row can be
// corrected more than once, so this list holds entries and not ids, and the
// guard below reads each id's entries as a chain rather than as one value.
const CORRECTED_IDS = [
	'2.3',
	'2.7',
	'3.3',
	'11.2',
	// 2026-09-22, the closed rows: the build wave shipped them and the ledger
	// recorded only the prose.
	'3.4',
	'3.13',
	'5.4',
	'6.5',
	'6.6',
	'6.10',
	'6.14',
	'9.2',
	'10.1',
	'11.2',
	'11.9',
	'11.13',
	'11.26',
	'12.9',
	// 2026-09-22, the rows we already had: the note predated the work.
	'1.1',
	'1.3',
	'1.5',
	'2.1',
	'2.5',
	'2.12',
	'2.15',
	'2.19',
	'3.10',
	'4.2',
	'4.22',
	'5.1',
	'5.2',
	'6.4',
	'6.12',
	'11.21',
	// 2026-09-22, the rows where the corpus was already right and this file was
	// behind it, plus one absence feature nobody had re-read.
	'7.7',
	'9.10',
	'11.22',
	'13.11',
	'13.17',
]

// Rows round 3 added to the list on 2026-09-09, from GLPI 11.0.8 and Zammad
// 7.1.3 driven locally. They are logged in `_rerated` with `cause: 'added'`
// and a null `from`, because there was no previous rating to move.
const ROUND3_ADDED_IDS = [
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

// Round 4's 104 rows used to be pinned here too. They are not rows any more:
// they were added to this file and to nothing else, and they are now 98 of the
// 146 proposals in `pending`, which carry no `addedOn` because they were never
// added to the scored list. The guards below therefore cover round 3's 19.
const ADDED_IDS = [...ROUND3_ADDED_IDS]

// The date each batch above was added, so the guard can check a row against
// its OWN round instead of against whichever round happened to be last.
const ADDED_ON = {
	...Object.fromEntries(ROUND3_ADDED_IDS.map((id) => [id, '2026-09-09'])),
}

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
//
// 4.22, upload with drag and drop, left the list on 2026-09-22. It did not
// shrink because a rival lost something: the case files leaf binds drop on its
// root and PUTs over DAV, so the row was ours all along and the note describing
// a retired tab was the only thing saying otherwise.
const BEHIND_EVERY_RIVAL = ['2.4', '4.16', '9.1', '11.10', '12.7']

// Two labels are identical in English and Dutch because the Dutch IS the
// English: `StUF (BG, ZKN, DCR)` is a Dutch standard's own name, and `Intake`
// is the same word in both. Everything else must genuinely differ, which is
// how a row that was added without a translation gets caught.
const IDENTICAL_BY_DESIGN = new Set(['12.4'])

describe('capabilityComparison data', () => {
	it('holds exactly the ids the corpus issued', () => {
		// THE GUARD THIS FILE WAS MISSING ON 2026-09-10, and the reason 104
		// rows the corpus had never issued reached a customer page. Both files
		// are written by `scripts/sync-capability-comparison.mjs` in one run
		// from one source, so a hand edit to either one alone fails here and
		// names the ids that appeared or went missing.
		//
		// It compares the SET and the ORDER, because the corpus's order is the
		// numbering a reader sees in the first column.
		expect(data.capabilities.map((c) => c.id)).toEqual(corpusIds.rows)
		expect(data.pending.map((c) => c.id)).toEqual(corpusIds.pending)
	})

	it('carries the 225 rows, 146 proposals, 17 areas and 5 columns', () => {
		expect(data.capabilities).toHaveLength(ROW_COUNT)
		expect(data.pending).toHaveLength(PENDING_COUNT)
		expect(data.areas).toHaveLength(AREA_COUNT)
		expect(data.systems).toHaveLength(5)
	})

	it('gives every declared area at least one row or one proposal', () => {
		// An area with nothing in it is a heading over nothing. Areas 14 to 17
		// hold proposals and no rows today, which is a real state and not a
		// filing error: they are four questions round 4 raised that had
		// nowhere to go, and no competitor has been read against any of them.
		const filled = new Set([
			...data.capabilities.map((c) => c.area),
			...data.pending.map((c) => c.area),
		])
		const empty = data.areas.filter((a) => !filled.has(a.key))
		expect(empty.map((a) => a.key)).toEqual([])
	})

	it('gives every row and every proposal a unique id', () => {
		const ids = [
			...data.capabilities.map((c) => c.id),
			...data.pending.map((c) => c.id),
		]
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('marks every proposal pending, rates only our own column on it', () => {
		// A proposal with a competitor rating is the failure this split exists
		// to prevent: one product was read and the other four were not, so any
		// value but `unknown` in their columns is a guess published as a
		// reading. Our own column is rated, because we can read our own code,
		// and an `unknown` there would understate our score for free.
		const rivals = data.systems.filter((s) => !s.isSelf).map((s) => s.key)
		const bad = []
		for (const row of data.pending) {
			if (row.status !== 'pending') {
				bad.push(`${row.id}/status=${row.status}`)
			}
			if (!RATING_COLUMNS.includes(row.dossiq)) {
				bad.push(`${row.id}/dossiq=${row.dossiq}`)
			}
			if (!row.dossiqNote || row.dossiqNote.length < 21) {
				bad.push(`${row.id}/no evidence`)
			}
			for (const key of rivals) {
				if (row[key] !== 'unknown') {
					bad.push(`${row.id}/${key}=${row[key]}`)
				}
			}
		}
		expect(bad).toEqual([])
	})

	it('pins the proposals we have not measured ourselves on', () => {
		// One, and the corpus says why: Q13.25 asks whether a role can take a
		// right away as well as grant one, and answering it needs the
		// permission resolver read end to end rather than grepped. The batch
		// that proposed it marked the cell unmeasured instead of guessing at
		// our own product, which is the same rule the competitor columns
		// follow. Pinned rather than asserted empty, so the list shrinking is
		// visible and a second one cannot appear quietly.
		const unmeasured = data.pending.filter((p) => !RATINGS.includes(p.dossiq))
		expect(unmeasured.map((p) => p.id)).toEqual(OURS_UNMEASURED)
		for (const row of unmeasured) {
			expect(row.dossiqNote.toLowerCase(), row.id).toContain('unmeasured')
		}
	})

	it('keeps a proposal out of every tally', () => {
		// The whole point of the second list. `overallTallies` counts rows, so
		// its total is the row count and never the sum of the two lists. This
		// is the assertion that fails if somebody later merges `pending` into
		// `capabilities` to simplify the template.
		const totals = overallTallies(data)
		for (const system of data.systems) {
			expect(totals[system.key].total, system.key).toBe(ROW_COUNT)
		}
		const areas = groupByArea(data)
		const rowsInAreas = areas.reduce((n, a) => n + a.capabilities.length, 0)
		const pendingInAreas = areas.reduce((n, a) => n + a.pending.length, 0)
		expect(rowsInAreas).toBe(ROW_COUNT)
		expect(pendingInAreas).toBe(PENDING_COUNT)
		for (const area of areas) {
			expect(area.tallies.dossiq.total, area.key).toBe(
				area.capabilities.length,
			)
		}
	})

	it('names the corpus it was re-issued from', () => {
		// A copy that does not say where it came from is how the last one
		// drifted: the old `_comment` cited a private repo that no longer
		// resolves, and nobody could check the claim.
		expect(data.corpus.repo).toBe('ConductionNL/market-intelligence')
		expect(data.corpus.file).toBe('procest/_round4/tools/corpus-rows.json')
		expect(data.corpus.rows).toBe(ROW_COUNT)
		expect(data.corpus.pending).toBe(PENDING_COUNT)
	})

	it('files every row and every proposal under a declared area', () => {
		const areas = new Set(data.areas.map((a) => a.key))
		const orphans = [...data.capabilities, ...data.pending].filter(
			(c) => !areas.has(c.area),
		)
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

	it('dates every added row on the round that added it', () => {
		// This used to assert every added row carried `rowsAddedOn`, which held
		// only while exactly one round had ever added rows. A second round made
		// that assertion a liar in the helpful direction: it would have forced
		// round 3's 19 rows to be re-dated to round 4's day, silently claiming
		// we asked those questions a day later than we did. Each row is now
		// checked against its own round, and `rowsAddedOn` is asserted to be
		// the most recent of them rather than the only one.
		const added = data.capabilities.filter((c) => c.addedOn)
		expect(added.map((c) => c.id).sort()).toEqual([...ADDED_IDS].sort())
		expect(data.rowsAddedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		for (const row of added) {
			expect(row.addedOn, row.id).toBe(ADDED_ON[row.id])
			expect(row.addedOn > data.comparedOn, row.id).toBe(true)
			expect(row.addedOn <= data.rowsAddedOn, row.id).toBe(true)
		}
		const latest = added
			.map((row) => row.addedOn)
			.sort()
			.at(-1)
		expect(data.rowsAddedOn).toBe(latest)
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
		// Rows only. A proposal arrives from the corpus, which is written in
		// English, and `labelFor` falls back to English when there is no Dutch
		// rather than rendering a blank cell. Asserting Dutch on all 146 would
		// force a translation of a question that may never become a row, and
		// the honest place to translate one is the change that promotes it.
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
			[...RERATED_IDS, ...CORRECTED_IDS, ...ADDED_IDS].sort(),
		)
		for (const entry of data._rerated) {
			expect(entry.from, entry.id).not.toBe(entry.to)
			expect(RATINGS, entry.id).toContain(entry.to)
			expect(entry.on, entry.id).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(entry.reason.length, entry.id).toBeGreaterThan(20)
			expect(['built', 'added', 'corrected'], entry.id).toContain(entry.cause)
		}
	})

	it('separates a correction from a capability we shipped', () => {
		// `built` says the product changed between two readings. `corrected`
		// says the first reading was wrong and nothing was shipped. They read
		// the same on the page as "we moved our own rating", and they are not
		// the same claim: one is a release note, the other is an erratum.
		const corrected = data._rerated.filter((e) => e.cause === 'corrected')
		expect(corrected.map((e) => e.id).sort()).toEqual([...CORRECTED_IDS].sort())
		for (const entry of corrected) {
			expect(RATINGS, entry.id).toContain(entry.from)
			expect(entry.on > data.comparedOn, entry.id).toBe(true)
			// The evidence, not a note saying there is evidence somewhere.
			expect(entry.reason.length, entry.id).toBeGreaterThan(60)
		}
	})

	it('logs an added row as an addition and not as a correction', () => {
		// A row that never had a rating cannot have been corrected. Recording
		// one as `built` would tell a reader we shipped something, when what
		// happened is that we started asking a question we had ducked.
		const byId = new Map(data.capabilities.map((c) => [c.id, c]))
		const added = data._rerated.filter((e) => e.cause === 'added')
		expect(added.map((e) => e.id).sort()).toEqual([...ADDED_IDS].sort())
		for (const entry of added) {
			expect(entry.from, entry.id).toBeNull()
			// Dated from the row, not from `rowsAddedOn`, for the same reason
			// as the guard above: two rounds have added rows now.
			expect(entry.on, entry.id).toBe(byId.get(entry.id).addedOn)
		}
	})

	it('keeps every correction note pinned to the rating it explains', () => {
		// The failure this catches: a row is edited again later and the note
		// beside it goes on describing the previous value. A note that
		// disagrees with its own row is worse than no note.
		//
		// Read as a CHAIN, because a row can move twice. 11.2 went yes to no
		// and then no to yes, and comparing every entry to today's rating
		// would call the first one drift. So each entry has to hand its `to`
		// to the next entry's `from`, and the last `to` is the row's rating.
		// That is stricter than the single comparison it replaces: it also
		// catches a middle entry nobody would otherwise read again.
		const byId = new Map(data.capabilities.map((c) => [c.id, c]))
		const chains = new Map()
		for (const entry of data._rerated) {
			chains.set(entry.id, [...(chains.get(entry.id) ?? []), entry])
		}
		const broken = []
		for (const [id, entries] of chains) {
			const ordered = [...entries].sort((a, b) =>
				a.on < b.on ? -1 : a.on > b.on ? 1 : 0,
			)
			for (let i = 1; i < ordered.length; i++) {
				if (ordered[i].from !== ordered[i - 1].to) {
					broken.push(
						`${id} ${ordered[i].on} follows ${ordered[i - 1].to}`,
					)
				}
			}
			if (byId.get(id)?.dossiq !== ordered.at(-1).to) {
				broken.push(
					`${id} ends ${ordered.at(-1).to}, row is ${byId.get(id)?.dossiq}`,
				)
			}
		}
		expect(broken).toEqual([])
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
	it('keeps the declared ordering, Intake first and the newest area last', () => {
		// The areas render in the order the file declares them, which is the
		// audit's numbering. Round 4's four areas are numbered 14 to 17 and
		// therefore append; sorting the areas here would put 13.1 above 1.1 on
		// a page whose first column is the row number.
		const groups = groupByArea(data, 'en')
		expect(groups[0].label).toBe('Intake')
		expect(groups[12].label).toBe('Access and privacy')
		expect(groups[groups.length - 1].label).toBe('Multi-organisation')
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
		// 13 rows and 5 proposals: Q1.14 to Q1.16 from round 4's batches, and
		// 1.17 and 1.18, which dossiq published as 1.14 and 1.15 before the
		// corpus renumbered them. The tally counts the rows only: a proposal
		// has one rated column out of five and belongs in no score.
		expect(intake.capabilities).toHaveLength(13)
		expect(intake.pending).toHaveLength(5)
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

describe('the authored fields survive a corpus re-issue', () => {
	// `provider`, `feature` and their confidence are authored by hand and are
	// NOT in the parity corpus, so nothing can regenerate them. An earlier
	// version of scripts/sync-capability-comparison.mjs rebuilt each row from
	// the corpus alone, which dropped all 371 of them and the providers
	// dictionary while still printing "re-issued 225 rows and 146 pending" and
	// leaving a well formed file. These assertions are what notices.
	const everyRow = [...data.capabilities, ...data.pending]

	it('gives every capability a provider', () => {
		const without = everyRow.filter((row) => !row.provider).map((row) => row.id)

		expect(without, `rows with no provider: ${without.join(', ')}`).toEqual([])
	})

	it('records how each provider was derived', () => {
		const without = everyRow
			.filter((row) => row.provider && !row.providerHow)
			.map((row) => row.id)

		expect(
			without,
			`rows with a provider and no derivation: ${without.join(', ')}`,
		).toEqual([])
	})

	it('keeps the providers dictionary that labels them', () => {
		expect(Array.isArray(data.providers)).toBe(true)
		expect(data.providers.length).toBeGreaterThan(0)

		const declared = new Set(data.providers.map((entry) => entry.key))
		const undeclared = [
			...new Set(
				everyRow
					.map((row) => row.provider)
					.filter((key) => !declared.has(key)),
			),
		]

		expect(
			undeclared,
			`providers used but not declared: ${undeclared.join(', ')}`,
		).toEqual([])
	})

	it('states a confidence wherever it states a feature', () => {
		const without = everyRow
			.filter((row) => row.feature && !row.featureConfidence)
			.map((row) => row.id)

		expect(
			without,
			`rows with a feature and no confidence: ${without.join(', ')}`,
		).toEqual([])
	})
})
