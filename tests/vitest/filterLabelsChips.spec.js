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

describe('a saved view\'s line leaves the working-list rules out', () => {
	const labels = page('Cases').config.filterLabels

	// Every view of the cases list carries them, so they tell one view from
	// another in nothing; "Weergaven in mijn menu" read "statusHiddenInLists
	// nee, isDraft nee" under Woo-verzoeken (DqPersoonlijkeInstellingen).
	it.each([['statusHiddenInLists'], ['isDraft'], ['isTemplate']])('hides %s from the view summary', (key) => {
		expect(labels[key]).toEqual({ hidden: true })
	})
})

describe('a saved view\'s filters read as the board words them', () => {
	const labels = page('Cases').config.filterLabels

	// DqFilterBewerken: "Wacht op inwoner ja" under Keep what is saved, and the
	// Active line named the handler by the schema's "Toegewezen aan" where the
	// board and the column header say "Behandelaar".
	it('reads Status: wacht op inwoner for waitingOnApplicant', () => {
		expect(dutchChip(labels, 'waitingOnApplicant', 'true')).toBe('Status: wacht op inwoner')
	})

	it('names the handler filter Behandelaar', () => {
		expect(nl[labels.assignee.label]).toBe('Behandelaar')
	})
})

describe('the cases list names the open-or-closed filter', () => {
	const labels = page('Cases').config.filterLabels

	it('reads Status: lopend for ?isFinalStatus=false', () => {
		expect(dutchChip(labels, 'isFinalStatus', 'false')).toBe('Status: lopend')
	})

	it('names the team filter Team, so the @myTeam queue chip reads "Team: Woo en informatie"', () => {
		expect(nl[labels.assignedGroup.label]).toBe('Team')
	})

	it('names the deadline Termijn and asks for a range from today in words', () => {
		expect(nl[labels.deadline.label]).toBe('Termijn')
		expect(labels.deadline.relative).toBe(true)
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
