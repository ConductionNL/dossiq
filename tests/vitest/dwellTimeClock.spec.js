/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which clock the process-mining page says its numbers are on.
 *
 * THE HEADER IS THE ONLY DISCLOSURE ON THE PAGE. Working hours counted on the
 * organisation calendar and working hours counted as working days times eight
 * draw the identical chart: both are hours, both are plausible, and one of
 * them calls a case that sat an hour on Friday and an hour on Monday sixteen
 * hours of work. Nothing else on the page can tell them apart, so the label
 * is asserted per clock rather than assumed.
 *
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	buildAssigneeRows,
	buildDwellSeries,
	CLOCKS,
	headlineHours,
	wallHoursLabel,
	workingHoursLabel,
} from '../../src/views/processMining/processMiningShaping.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const clockSource = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'ProcessMining', 'WorkingClock.php'),
	'utf8',
)

/** A translate that returns the key, so the assertions read the English. */
const t = (s) => s

describe('the clock names', () => {
	it('are the ones the server sends', () => {
		// One spelling on both sides. A mismatch would fall through to the
		// wall-clock label on a report really counted on the calendar, and
		// nothing on screen would look wrong.
		expect(clockSource).toContain(`CLOCK_CALENDAR = '${CLOCKS.CALENDAR}'`)
		expect(clockSource).toContain(
			`CLOCK_DAYS_TIMES_EIGHT = '${CLOCKS.DAYS_TIMES_EIGHT}'`,
		)
		expect(clockSource).toContain(`CLOCK_WALL = '${CLOCKS.WALL}'`)
	})
})

describe('the headline column title', () => {
	it('says working hours only when the calendar was read', () => {
		expect(workingHoursLabel(CLOCKS.CALENDAR, t)).toBe('Working hours')
	})

	it('says how the approximation was made when it was approximated', () => {
		expect(workingHoursLabel(CLOCKS.DAYS_TIMES_EIGHT, t)).toBe(
			'Working hours (working days x 8)',
		)
	})

	it('says wall clock when nothing was converted', () => {
		expect(workingHoursLabel(CLOCKS.WALL, t)).toBe('Hours, wall clock')
	})

	it('says wall clock for a clock it does not know', () => {
		// A server that grows a fourth clock must not have its numbers
		// labelled as calendar hours by a page that has not been taught it.
		expect(workingHoursLabel('something-new', t)).toBe('Hours, wall clock')
		expect(workingHoursLabel(undefined, t)).toBe('Hours, wall clock')
	})

	it('is never the same as the second column', () => {
		for (const clock of Object.values(CLOCKS)) {
			expect(workingHoursLabel(clock, t)).not.toBe(wallHoursLabel(t))
		}
	})
})

describe('the headline number', () => {
	it('is the working-hours one when the row carries it', () => {
		expect(headlineHours({ medianWorkingHours: 1, medianHours: 65 })).toBe(1)
	})

	it('falls back to the wall clock for a row that carries none', () => {
		// Every row from a server that predates this change. The label then
		// says wall clock, so the fallback is disclosed rather than disguised.
		expect(headlineHours({ medianHours: 65 })).toBe(65)
		expect(headlineHours(undefined)).toBe(0)
	})

	it('drives the chart bars, not just the axis title', () => {
		const series = buildDwellSeries(
			[
				{
					statusName: 'In behandeling',
					medianWorkingHours: 1,
					medianHours: 65,
				},
			],
			'Working hours',
		)
		// A chart whose title said working hours and whose bars were the wall
		// clock is the exact failure this change is about.
		expect(series[0].data).toEqual([1])
	})
})

describe('the by-handler rows', () => {
	const rows = buildAssigneeRows(
		[
			{ actor: 'anna', medianWorkingHours: 6, medianHours: 40, visitCount: 3 },
			{ actor: '', medianWorkingHours: 2, medianHours: 10, visitCount: 1 },
		],
		t,
	)

	it('carry both numbers per handler', () => {
		expect(rows[0]).toEqual({
			actor: 'anna',
			actorLabel: 'anna',
			workingHours: 6,
			wallHours: 40,
			visitCount: 3,
		})
	})

	it('name unattributed time rather than dropping it', () => {
		// A table that omits it shows less work than was done, and nothing on
		// the page says why it disagrees with the phase table beside it.
		expect(rows).toHaveLength(2)
		expect(rows[1].actorLabel).toBe('Not recorded')
	})

	it('answer an empty list for a report that carries none', () => {
		expect(buildAssigneeRows(undefined, t)).toEqual([])
	})
})

describe('the page declares the by-handler widget', () => {
	it('has it on the process mining page with a component behind it', () => {
		const page = manifest.pages.find((p) => p.route === '/process-mining')
		const widget = page.config.widgets.find(
			(w) => w.id === 'pm-dwell-by-assignee',
		)
		expect(widget).toBeTruthy()
		// A widget with no slot renders an empty panel and says nothing.
		expect(page.slots['widget-pm-dwell-by-assignee']).toBe(
			'PmDwellByAssigneeWidget',
		)
	})

	it('gives it a place on the grid, so it is on screen', () => {
		const page = manifest.pages.find((p) => p.route === '/process-mining')
		expect(
			page.config.layout.some((l) => l.widgetId === 'pm-dwell-by-assignee'),
		).toBe(true)
	})

	it('registers the component, so the slot resolves', () => {
		// src/registry.js, not the customComponents map this used to read:
		// that map is retired, and a `kind: 'widget'` entry is what the
		// renderer resolves a slot name against now.
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(registry).toContain(
			"import PmDwellByAssigneeWidget from './views/processMining/PmDwellByAssigneeWidget.vue'",
		)
		expect(registry).toMatch(
			/PmDwellByAssigneeWidget: \{\s*\n\s*kind: 'widget',/,
		)
	})
})
