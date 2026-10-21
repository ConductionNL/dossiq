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
 *   1. the queue page names NO source, so a ninth mechanism needs no edit here
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

const { OFFER_HIDE_GROUP, canDismiss, offerInsteadOfDismissing } =
	await import('../../src/utils/personalQueueHelpers.js')

const catalogue = readFileSync(
	resolve(root, 'lib/Service/Queue/QueueSourceCatalogue.php'),
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

	it('the queue page names no source', () => {
		// A source name on the page is a source the page has to be edited for,
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

		// The plan-an-item dialog posts to its own endpoint; what must name no
		// source is the queue the page draws.
		const declared = JSON.stringify(page('PersonalQueue').config.widgets)
		for (const name of names) {
			expect(declared, `${name} is named on the queue page`).not.toContain(
				name,
			)
		}
	})

	it('draws the groups the server sent through the library work queue, which names unreadable sources', () => {
		const widgets = page('PersonalQueue').config.widgets
		expect(widgets).toHaveLength(1)
		expect(widgets[0].type).toBe('work-queue')
		expect(widgets[0].content).toEqual({
			source: { url: '/apps/dossiq/api/personal-queue' },
			hide: '/apps/dossiq/api/personal-queue/groups/{group}/hide',
			show: '/apps/dossiq/api/personal-queue/groups/{group}/show',
			emptyText: 'Nothing is waiting on you.',
		})
		expect(page('PersonalQueue').config.layout.map((cell) => cell.widgetId)).toEqual([widgets[0].id])
	})

	it('plans an item through the library action dialog, which refreshes the queue', () => {
		// CnActionDialog posts the item and fires `cn:page:refresh`, and the
		// work queue reads again on that channel.
		const plan = page('PersonalQueue').config.headerActions.find((action) => action.id === 'plan-item')
		expect(plan.type).toBe('open-modal')
		expect(plan.target).toBe('CnActionDialog')
		expect(plan.label).toBe('Plan an item')
		expect(plan.props.config.confirm.request).toEqual({
			method: 'POST',
			url: '/apps/dossiq/api/personal-queue/planned-items',
		})
		expect(plan.props.config.fields.map((field) => field.field)).toEqual(['template', 'title', 'startsAt'])
	})

	it('keeps the way back to My work', () => {
		expect(page('PersonalQueue').config.breadcrumb).toEqual({ label: 'My work', route: 'MyWorkHome' })
	})
})

describe('the page is a page, and not a second case index', () => {
	it('the personal queue is a manifest page with no app component', () => {
		expect(page('PersonalQueue').type).toBe('dashboard')
		expect(page('PersonalQueue').component).toBeUndefined()
	})

	it('it does not name the case schema, so it is not a second index over it', () => {
		expect(page('PersonalQueue').config.schema).toBeUndefined()
		expect(page('PersonalQueue').config.register).toBeUndefined()
	})

	it('the unclaimed pool keeps its own meaning, as a view of the case list', () => {
		// Two different questions. The team queue is every open case nobody has
		// picked up, a team surface and a view of Cases now; `PersonalQueue` is
		// one person's own, across stores, and stays a page.
		const teamQueue = page('Cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue')
		expect(teamQueue.overrides.lockedFilters.assignee).toBe('IS NULL')
		expect(page('Queue')).toBeUndefined()
		expect(page('PersonalQueue').type).toBe('dashboard')
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
