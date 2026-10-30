// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Following a case you do not own: the two verbs, the list, the lens, the one
 * control on the case page and the rule that makes a follower hear anything.
 *
 * THE VERB IS THE FIRST THING ASSERTED, for the reason the star's own file
 * records. Following is a PUT and stopping is a DELETE on the same path, and
 * every declarative action in the library writes POST or PUT: `executeApiCall`
 * maps anything that is not `PUT` to `post`. A row action that PUT in both
 * directions would look right, would report success, and would leave a
 * subscription nobody could take off. So the method is asserted per direction
 * rather than the call count.
 *
 * THE SECOND IS THAT A REFUSAL IS NOT AN EMPTY LIST. Reading the followers
 * needs `update` on the case, so a reader without it gets 403. Drawn as "nobody
 * follows this case", that is a claim about the audience nobody made to this
 * reader, and it is invisible: an empty list and a refused list render the same
 * way unless something keeps them apart.
 *
 * THE THIRD IS THE RECIPIENT BLOCK. The Follow button is decoration unless the
 * case schema addresses its watchers, and `{"watchers": true}` is spelled
 * WITHOUT a `kind` because it names a subscription list rather than a value to
 * look up. `NotificationAnnotationValidator` refuses anything but boolean true,
 * so `"yes"` is how a rule quietly addresses nobody.
 *
 * @spec openspec/changes/case-followers/specs/case-management/spec.md
 * @spec openspec/specs/case-management/spec.md#requirement-the-case-page-shows-one-follow-control-req-cm-40a
 */

import axios from '@nextcloud/axios'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	isFollowing,
	objectIdOf,
	setFollowing,
} from '../../src/services/watcherApi.js'
import { pageGrids, pageWidgets } from './helpers/pageViews.js'

const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'),
		'utf8',
	),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const page = (id) => manifest.pages.find((p) => p.id === id)
const caseDetail = page('CaseDetail')
const caseNotifications =
	register.components.schemas.case['x-openregister-notifications']

beforeEach(() => {
	vi.clearAllMocks()
	axios.put.mockResolvedValue({ data: { userId: 'you' } })
	axios.delete.mockResolvedValue({ data: {} })
	axios.get.mockResolvedValue({ data: { results: [], total: 0 } })
})

describe('the state is read off the object', () => {
	it('treats an absent marker as not following', () => {
		// `@self.watching` is omitted entirely for an anonymous read, where
		// there is no "you" to answer for.
		expect(isFollowing({ id: 'x' })).toBe(false)
		expect(isFollowing({ id: 'x', '@self': {} })).toBe(false)
		expect(isFollowing(null)).toBe(false)
		// A control, so the three above cannot pass because the reader is broken.
		expect(isFollowing({ '@self': { watching: true } })).toBe(true)
	})

	it('reads the id from either shape a list row comes in', () => {
		expect(objectIdOf({ id: 'a' })).toBe('a')
		expect(objectIdOf({ '@self': { id: 'b' } })).toBe('b')
		expect(objectIdOf({})).toBe('')
	})
})

describe('the two verbs', () => {
	it('follows with a PUT on the watch path', async () => {
		await setFollowing('case-7', true)

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/watch',
		)
	})

	it('stops with a DELETE on the same path', async () => {
		await setFollowing('case-7', false)

		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/watch',
		)
	})
})

describe('following is declared on the case page', () => {
	it('is the header control only: the Follow toggle and its popover list the followers', () => {
		// Ruben, 20 Oct 2026: followers show only in the header popover. The
		// case page shows ONE Follow control, nextcloud-vue's CnFollowToggle
		// beside the title, whose popover lists who follows the case (403
		// closes quietly, managers may remove). A Followers section on the
		// People tab listed the same people a second time, and a strip in the
		// banner row once drew a second Follow button that did not sync.
		expect(caseDetail.config.follow).not.toBe(false)
		const people = caseDetail.config.widgets.find((w) => w.id === 'case-people-panel')
		expect(people.content.sections.some((s) => s.label === 'Followers')).toBe(false)
		expect(JSON.stringify(manifest)).not.toContain('"type": "case-followers"')
		expect(JSON.stringify(manifest)).not.toContain('"type":"case-followers"')
		expect(registrySource).not.toContain("'case-followers': {")
		expect(registrySource).not.toContain("'case-follow': {")
	})

	it('leaves no Follow strip in the banner row', () => {
		const stack = caseDetail.config.widgets.find((w) => w.id === 'case-banner-stack')
		expect(stack.content.sections.map((s) => s.widgetId)).toEqual(['case-archived', 'case-status-declaration', 'case-attention'])
		expect(fs.existsSync(path.join(ROOT, 'src', 'components', 'case', 'CaseFollowStrip.vue'))).toBe(false)
		expect(fs.existsSync(path.join(ROOT, 'src', 'components', 'case', 'CaseFollowersPanel.vue'))).toBe(false)
	})
})

describe('the lens and the tile', () => {
	it('offers one Following chip on Cases over the platform lens, and no Favourites chip', () => {
		const chips = page('Cases').config.quickFilters
		const chip = chips.find((c) => c.label === 'Following')
		expect(chips.some((c) => c.label === 'Favourites')).toBe(false)
		expect(chips.some((c) => c.filter && '_favourite' in c.filter)).toBe(false)

		expect(chip).toBeTruthy()
		// `_watching` is resolved INSIDE the query the way `_unread` and
		// `_favourite` are, so the page, the total and the facets agree.
		expect(chip.filter._watching).toBe(true)
		// No `isFinalStatus`: you follow a case to hear how it ends.
		expect(chip.filter.isFinalStatus).toBeUndefined()
		expect(chip.filter.statusHiddenInLists).toBe(false)
		expect(chip.filter.isDraft).toBe(false)
	})

	it('puts the tile on My Work, pointing at that same chip', () => {
		const home = page('MyWorkHome')
		const tile = pageWidgets(home).find((w) => w.id === 'followed-cases')

		expect(tile).toBeTruthy()
		expect(tile.content.source.filter._watching).toBe(true)
		// The tile and the chip have to be the same lens, or a handler reads
		// two different sets of the cases they follow on two pages.
		expect(tile.content.viewAllRoute.query._watching).toBe('true')
		expect(tile.content.rowRoute).toBe('CaseDetail')
		expect(
			pageGrids(home).some((grid) =>
				grid.layout.some((c) => c.widgetId === 'followed-cases'),
			),
			'followed-cases has no cell on the My Work grid',
		).toBe(true)
	})

	it('fills its rows on both pages: no cell overlaps another', () => {
		// Each grid on its own: the landing page draws its own grid and the
		// chosen view's below it, so two grids may use the same rows.
		for (const [id, layout] of [
			['CaseDetail', page('CaseDetail').config.layout],
			...pageGrids(page('MyWorkHome')).map((g) => ['MyWorkHome', g.layout]),
		]) {
			const grid = new Map()
			for (const cell of layout) {
				expect(
					cell.gridX + cell.gridWidth,
					cell.widgetId,
				).toBeLessThanOrEqual(12)
				for (let y = cell.gridY; y < cell.gridY + cell.gridHeight; y++) {
					for (let x = cell.gridX; x < cell.gridX + cell.gridWidth; x++) {
						const key = `${x},${y}`
						expect(
							grid.has(key),
							`${cell.widgetId} overlaps ${grid.get(key)} at ${key} on ${id}`,
						).toBe(false)
						grid.set(key, cell.widgetId)
					}
				}
			}
		}
	})
})

describe('what a follower actually hears', () => {
	it('addresses the watchers when the case moves status', () => {
		const rule = caseNotifications.caseMovedForItsFollowers

		expect(rule, 'the case schema addresses no followers').toBeTruthy()
		// `transition` and not `updated`: ObjectTransitionedEvent fires when
		// the status engine moves the case, where `updated` fires on every
		// save and a follower would hear about every field edit.
		expect(rule.trigger.type).toBe('transition')
		expect(rule.enabled).toBe(true)
		expect(rule.recipients).toEqual([{ watchers: true }])
	})

	it('spells the recipient block the way the validator accepts it', () => {
		// `{"watchers": true}` names a subscription list rather than a value to
		// look up, so it carries NO `kind`. Anything but boolean true is
		// refused rather than coerced: `"yes"` is how a rule quietly addresses
		// nobody, and it would be refused at save time with the button on the
		// case page still working.
		const blocks = Object.values(caseNotifications)
			.flatMap((rule) => rule.recipients ?? [])
			.filter((r) => Object.hasOwn(r, 'watchers'))

		expect(blocks.length).toBeGreaterThan(0)
		for (const block of blocks) {
			expect(block.watchers).toBe(true)
			expect(block.kind).toBeUndefined()
		}
	})

	it('adds the followers to the escalation without taking the responders off', () => {
		// A follower hearing an escalation must not cost the people who have to
		// act on it their own notification.
		expect(caseNotifications.caseDeclaredMajor.recipients).toEqual([
			{ kind: 'field', field: 'majorResponders' },
			{ watchers: true },
		])
	})

	it('leaves the two creation rules alone', () => {
		// Nobody can be following a case at the moment it is created, so a
		// watchers block on a `created` trigger addresses nobody by
		// construction and would read as coverage this change does not have.
		for (const name of ['caseAssigned', 'caseHandoffIntake']) {
			expect(caseNotifications[name].trigger.type).toBe('created')
			expect(
				caseNotifications[name].recipients.some((r) =>
					Object.hasOwn(r, 'watchers'),
				),
			).toBe(false)
		}
	})
})
