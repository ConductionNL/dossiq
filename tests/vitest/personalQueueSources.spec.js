// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The queue page is fed by declarations, not by a list in the page.
 *
 * 🔴 THE FAILURE THIS GUARDS IS SILENT AND HAS ALREADY HAPPENED. dossiq's My
 * Work tile showed engine tasks and nothing else, and its own manifest note
 * said so plainly, while assigned cases, consultations, approvals and mentions
 * each had a page of their own. Nothing was red, because nothing was checking
 * that the page and the mechanisms agreed.
 *
 * So these specs read the real files and hold three things:
 *
 *   1. the queue view names NO source, so a ninth mechanism needs no edit here
 *   2. the declared sources are the eight the design names, by name
 *   3. what a reader may do to an item cannot remove live work
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	t: (app, text) => text,
}))

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const { OFFER_HIDE_GROUP, canDismiss, offerInsteadOfDismissing, visibleGroups } =
	await import('../../src/utils/personalQueueHelpers.js')

const catalogue = readFileSync(
	resolve(root, 'lib/Service/Queue/QueueSourceCatalogue.php'),
	'utf8',
)
const queueView = readFileSync(
	resolve(root, 'src/views/queue/PersonalQueueView.vue'),
	'utf8',
)
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))

/**
 * One page's entry from the manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

/**
 * The source names the backend declares, read off the catalogue.
 *
 * @return {Array<string>} The class short names.
 */
function declaredSources() {
	return [...catalogue.matchAll(/^\t\t([A-Za-z]+Source)::class,$/gm)].map(
		(m) => m[1],
	)
}

describe('every mechanism reaches the queue by declaring itself', () => {
	it('declares one source per mechanism the design names', () => {
		expect(declaredSources()).toEqual([
			'AssignedCasesSource',
			'CoordinatorSeatSource',
			'EngineTaskSource',
			'ConsultationSource',
			'ApprovalSource',
			'MentionSource',
			'CoveredWorkSource',
			'PlannedItemSource',
			// task-dependencies-and-the-next-planned-action, row 3.28. A
			// planned action with an owner and a date is work waiting on a
			// named person, which is what this queue is for, and it was the
			// only such mechanism that did not appear here: the case said
			// what happened next and the person who had to do it found out by
			// opening the case.
			'PlannedActionSource',
			// the-doors-onto-the-split-and-the-incidents, #2957. An incident
			// raised on a case is a report handed to a named inspector, and
			// until it was declared here it reached nobody's queue: the
			// record existed and the person who had to act on it found out by
			// opening the case.
			'OpenIncidentSource',
		])
	})

	it('the queue page hard-codes no source name', () => {
		// A source name in the view is a source the view has to be edited for,
		// which is the coupling the contract exists to remove. The names are
		// checked against the catalogue above, so this cannot pass by the
		// catalogue being empty.
		const names = [
			'assigned-cases',
			'coordinator-seat',
			'consultations',
			'approvals',
			'mentions',
			'covered-work',
			'planned-items',
		]

		for (const name of names) {
			expect(queueView, `${name} is named in the queue view`).not.toContain(
				name,
			)
		}
	})

	it('renders the group heading and closing sentence the server sent', () => {
		expect(queueView).toContain('{{ group.label }}')
		expect(queueView).toContain('{{ group.closesWhen }}')
	})

	it('names a source that could not be read, rather than showing fewer items', () => {
		expect(queueView).toContain('could not be read, so this list is incomplete.')
		expect(queueView).toContain('queue-source-unavailable-')
	})
})

describe('the page is a page, and not a second case index', () => {
	it('the personal queue is a custom page', () => {
		expect(page('PersonalQueue').type).toBe('custom')
		expect(page('PersonalQueue').component).toBe('PersonalQueueView')
	})

	it('it does not name the case schema, so it is not a second index over it', () => {
		expect(page('PersonalQueue').config.schema).toBeUndefined()
		expect(page('PersonalQueue').config.register).toBeUndefined()
	})

	it('the unclaimed pool keeps its own page and its own meaning', () => {
		// Two pages, two different questions. `Queue` is every open case nobody
		// has picked up, a team surface; `PersonalQueue` is one person's own.
		expect(page('Queue').type).toBe('index')
		expect(page('Queue').config.filter.assignee).toBe('IS NULL')
		expect(page('PersonalQueue').route).not.toBe(page('Queue').route)
	})

	it('both new entries live under the existing My work group', () => {
		const layout = JSON.parse(
			readFileSync(resolve(root, 'src/menu-layout.json'), 'utf8'),
		)

		expect(layout.relocations.PersonalQueueMenu).toBe('WorkGroup')
		expect(layout.relocations.EndOfDayMenu).toBe('WorkGroup')
	})
})

describe('a person orders and hides, and cannot dismiss', () => {
	it('never lets a reader dismiss an item', () => {
		expect(canDismiss()).toBe(false)
	})

	it('offers the group instead', () => {
		expect(offerInsteadOfDismissing({ source: 'tasks' })).toEqual({
			offer: OFFER_HIDE_GROUP,
			group: 'tasks',
		})
	})

	it('hides a whole group and leaves the rest', () => {
		const groups = [{ key: 'tasks' }, { key: 'assigned-cases' }]

		expect(visibleGroups(groups, ['tasks'])).toEqual([{ key: 'assigned-cases' }])
	})

	it('shows every group when nothing is hidden', () => {
		const groups = [{ key: 'tasks' }, { key: 'assigned-cases' }]

		expect(visibleGroups(groups, [])).toEqual(groups)
	})

	it('offers no call that would remove an item', async () => {
		const api = readFileSync(
			resolve(root, 'src/services/personalQueueApi.js'),
			'utf8',
		)

		expect(api).not.toMatch(
			/export async function (dismiss|removeItem|deleteItem)/,
		)
	})
})
