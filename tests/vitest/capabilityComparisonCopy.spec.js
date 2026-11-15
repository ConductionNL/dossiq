/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words on the "How dossiq compares" docs page.
 *
 * The comparison moved from the in-app Features & roadmap page to
 * dossiq.conduction.nl/compare on 2026-10-07. These are the caveat assertions
 * the in-app tab carried, pointed at `docs/src/components/CapabilityComparison/
 * comparisonCopy.js`, the module the docs page renders from. It is pure, so
 * this runs in node with no Docusaurus and no React.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
 */

import { describe, expect, it } from 'vitest'
import {
	areaSummary,
	comparisonCopy,
	NL,
	ratingLabel,
	translate,
} from '../../docs/src/components/CapabilityComparison/comparisonCopy.js'
import data from '../../openspec/parity/capabilities.json'
import { groupByArea } from '../../src/utils/capabilityComparison.js'

const copy = comparisonCopy(data, 'en')
const caveats = copy.caveats.join('\n')
const notes = copy.systemNotes
	.flatMap((note) => [note.heading, ...note.paragraphs])
	.join('\n')

describe('comparison caveats on the docs page', () => {
	it('keeps all five mandatory caveats', () => {
		expect(caveats).toContain('open source software we could install and run')
		expect(caveats).toContain('already out of date')
		expect(caveats).toContain('is not proof that a product does')
		expect(caveats).toContain('run your own evaluation')
		expect(notes).toContain('owns no data')
		expect(notes).toContain('which flatters us')
	})

	it('names the column that owns no data, and the register it defers to', () => {
		const deferring = data.systems.filter((system) => system.ownsNoData)
		expect(deferring.length).toBeGreaterThan(0)
		for (const system of deferring) {
			expect(notes).toContain(system.name)
			expect(notes).toContain(system.ownsNoData)
		}
	})

	it('dates a column read on its own day, without moving the shared date', () => {
		const late = data.systems.filter(
			(system) => system.readOn && system.readOn !== data.comparedOn,
		)
		if (late.length) {
			expect(caveats).toContain(
				`We read ${data.systems.length - late.length} of the ${data.systems.length} systems`,
			)
			expect(notes).toContain('not on the date above')
		} else {
			expect(caveats).toContain(`We read all ${data.systems.length} systems`)
			expect(notes).not.toContain('not on the date above')
		}
	})

	it('dates the reading in the reader-s own language', () => {
		const english = new Intl.DateTimeFormat('en', {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'UTC',
		}).format(new Date(`${data.comparedOn}T00:00:00Z`))
		expect(caveats).toContain(english)
		expect(caveats).not.toContain(data.comparedOn)
	})

	it('counts only ratings it moved as corrections, not rows it added', () => {
		const moved = data._rerated.filter((entry) => entry.from !== null)
		const added = data._rerated.filter((entry) => entry.from === null)
		expect(added.length).toBeGreaterThan(0)
		expect(caveats).toContain(`We corrected ${moved.length} of our own ratings`)
		expect(caveats).not.toContain(
			`We corrected ${data._rerated.length} of our own ratings`,
		)
	})

	it('says which rows a later round added, and that rivals are unrated', () => {
		const added = data.capabilities.filter((row) => row.addedOn).length
		expect(caveats).toContain(`we added ${added} capabilities`)
		expect(caveats).toContain('the most recent of them on')
		expect(caveats).toContain('a guessed rating is worse than an empty cell')
	})

	it('says the proposals are proposed and counted in nothing', () => {
		expect(caveats).toContain(
			`Another ${data.pending.length} capabilities are proposed and not yet rated`,
		)
		expect(caveats).toContain('they are in no total on this page')
	})

	it('leaves no empty caveat behind', () => {
		for (const caveat of copy.caveats) {
			expect(caveat.trim()).not.toBe('')
			expect(caveat).not.toMatch(/\{\w+\}/)
		}
	})
})

describe('comparison copy in Dutch', () => {
	it('has a Dutch string for every caveat and every label', () => {
		const dutch = comparisonCopy(data, 'nl')
		expect(dutch.caveats).toHaveLength(copy.caveats.length)
		dutch.caveats.forEach((caveat, index) => {
			expect(caveat).not.toBe(copy.caveats[index])
			expect(caveat).not.toMatch(/\{\w+\}/)
		})
		for (const rating of ['yes', 'partial', 'no', 'unknown']) {
			expect(ratingLabel('nl', rating)).not.toBe(ratingLabel('en', rating))
		}
	})

	it('falls back to English for a string without a Dutch entry', () => {
		expect(translate('nl', 'No Dutch for {x}', { x: 1 })).toBe('No Dutch for 1')
		expect(Object.values(NL).every((value) => value.trim() !== '')).toBe(true)
	})

	it('summarises an area in the reader-s language', () => {
		const area = groupByArea(data, 'nl').find(
			(entry) => entry.capabilities.length > 0,
		)
		expect(areaSummary('nl', area)).toContain('Dossiq heeft er')
		expect(areaSummary('en', area)).toContain('Dossiq has')
	})
})
