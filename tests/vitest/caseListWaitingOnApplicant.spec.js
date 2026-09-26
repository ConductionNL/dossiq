// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The work list answers "what are we waiting on", as a query.
 *
 * 🔴 THE CHIP FILTERS ON A STORED FIELD, NOT ON A TIMER. Before this change the
 * only trace of an aanvullingsverzoek was a suspended clock, and a suspended
 * clock says the term stopped, not what was asked or who owes it. A chip
 * pointed at the pause would narrow the list plausibly and answer a different
 * question: cases suspended for ANY reason, including ones nobody asked the
 * applicant anything about.
 *
 * 🔴 AND IT IS NOT `isIncomplete`. That field is about required fields a
 * handler knowingly left empty at intake; this one is about something the
 * APPLICANT still owes. They look alike in a list and mean opposite things
 * about whose move it is, which is exactly why the filter is asserted here
 * rather than described in a note.
 *
 * The manifest is read rather than reproduced, so a chip that quietly changed
 * its filter fails here instead of reading fine in a diff.
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(
			ROOT,
			'lib',
			'Settings',
			'register.d',
			'63-aanvullingsverzoek.json',
		),
		'utf8',
	),
)

const cases = manifest.pages.find((p) => p.id === 'Cases')
const chip = cases.config.quickFilters.find(
	(q) => q.label === 'Waiting on the applicant',
)

describe('the work list answers what we are waiting on', () => {
	it('offers a chip for it', () => {
		expect(chip, 'the waiting-on-the-applicant chip is missing').toBeTruthy()
	})

	it('filters on the derived flag and not on a timer', () => {
		expect(chip.filter.waitingOnApplicant).toBe(true)
		expect(
			chip.filter.status,
			'a paused TERM is not the same fact',
		).toBeUndefined()
		expect(chip.filter.pauseDeadline).toBeUndefined()
	})

	it('is not the intake incompleteness flag wearing a different label', () => {
		expect(
			chip.filter.isIncomplete,
			'isIncomplete is about fields WE left empty, not about what the applicant owes',
		).toBeUndefined()
	})

	it('leaves out the cases nothing can be done about', () => {
		// A closed case cannot be waiting on anybody, and a draft is not a case
		// anyone is handling yet. Both would pad the count a team reads as work.
		expect(chip.filter.isFinalStatus).toBe(false)
		expect(chip.filter.isDraft).toBe(false)
	})

	it('narrows on the same hygiene as the chips beside it', () => {
		const unread = cases.config.quickFilters.find((q) => q.label === 'Unread')
		expect(chip.filter.statusHiddenInLists).toBe(
			unread.filter.statusHiddenInLists,
		)
	})
})

describe('the flag the chip reads is declared, derived and never typed', () => {
	const caseProps = fragment.components.schemas.case.properties

	it('is declared on the case, facetable, so the chip is a real query', () => {
		expect(caseProps.waitingOnApplicant).toBeTruthy()
		expect(caseProps.waitingOnApplicant.type).toBe('boolean')
		expect(
			caseProps.waitingOnApplicant.facetable,
			'a chip over a non-facetable field is a filter the store cannot answer',
		).toBe(true)
	})

	it('is read-only, because the requests are the truth and this is a mirror', () => {
		expect(caseProps.waitingOnApplicant.readOnly).toBe(true)
		expect(caseProps.waitingOnApplicantSince.readOnly).toBe(true)
	})

	it('carries the moment, so the list can show how long without opening the request', () => {
		expect(caseProps.waitingOnApplicantSince.format).toBe('date-time')
	})
})

describe('the request itself is the record, and it keeps what it asked for', () => {
	const request = fragment.components.schemas.aanvullingsverzoek

	it('names the missing things as a LIST, not one sentence', () => {
		expect(request.properties.missingItems.type).toBe('array')
		expect(
			request.properties.missingItems.items.properties.received,
		).toBeTruthy()
	})

	it('declares expiry as a state rather than leaving deletion possible', () => {
		expect(request.properties.state.enum).toContain('expired')
		const lifecycle = request.configuration['x-openregister-lifecycle']
		expect(lifecycle.final).toContain('expired')
		expect(lifecycle.transitions.expire.from).toEqual(['open'])
	})

	it('declares no reason list of its own', () => {
		// The typed reason belongs to pause-reason-with-chasing. A second
		// vocabulary here is exactly what that change exists to prevent, so the
		// field is a reference and carries no enum.
		expect(request.properties.pauseReason.enum).toBeUndefined()
	})
})
