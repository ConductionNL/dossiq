// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Following a case you do not own: the two verbs, the strip, the list, the
 * lens and the rule that makes a follower hear anything at all.
 *
 * THE VERB IS THE FIRST THING ASSERTED, for the reason the star's own file
 * records. Following is a PUT and stopping is a DELETE on the same path, and
 * every declarative action in the library writes POST or PUT: `executeApiCall`
 * maps anything that is not `PUT` to `post`. A strip that PUT in both
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
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseFollowersPanel from '../../src/components/case/CaseFollowersPanel.vue'
import CaseFollowStrip from '../../src/components/case/CaseFollowStrip.vue'
import {
	followerCountOf,
	isFollowing,
	objectIdOf,
} from '../../src/services/watcherApi.js'

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
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

const page = (id) => manifest.pages.find((p) => p.id === id)
const caseDetail = page('CaseDetail')
const caseNotifications =
	register.components.schemas.case['x-openregister-notifications']

/**
 * Mount the Follow strip over a case payload.
 *
 * @param {object} objectData The case as the page holds it.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountStrip(objectData) {
	const wrapper = mount(CaseFollowStrip, {
		// `objectData`, spelled the way `CnDetailWidgetHost.rendererProps()`
		// spells it. A test that mounted with `object` would pass while the
		// real page handed the widget nothing and drew Follow on every case.
		props: { objectId: 'case-7', objectData },
		global: {
			stubs: {
				NcButton: {
					props: { pressed: { type: Boolean, default: null } },
					template:
						'<button v-bind="$attrs" :aria-pressed="pressed === null ? null : String(pressed)">'
						+ '<slot name="icon" /><slot /></button>',
				},
				BellRing: { template: '<i class="bell-ring" />' },
				BellOutline: { template: '<i class="bell-outline" />' },
			},
		},
	})

	await wrapper.vm.$nextTick()

	return wrapper
}

/**
 * Mount the Followers panel and let its read settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPanel() {
	const wrapper = mount(CaseFollowersPanel, {
		props: { objectId: 'case-7' },
		global: {
			stubs: {
				NcLoadingIcon: { template: '<i class="loading" />' },
				NcEmptyContent: {
					props: { name: String, description: String },
					template:
						'<div class="empty">{{ name }} {{ description }}</div>',
				},
			},
		},
	})

	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()

	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.put.mockResolvedValue({ data: { userId: 'you' } })
	axios.delete.mockResolvedValue({ data: {} })
	axios.get.mockResolvedValue({ data: { results: [], total: 0 } })
})

describe('the strip reads off the object it was given', () => {
	it('declares the prop name the detail widget host actually binds', () => {
		const host = fs.readFileSync(
			path.join(
				ROOT,
				'node_modules/@conduction/nextcloud-vue/src/components/CnDetailWidgetHost/CnDetailWidgetHost.vue',
			),
			'utf8',
		)
		expect(host).toContain('objectData: this.object,')

		const strip = fs.readFileSync(
			path.join(ROOT, 'src/components/case/CaseFollowStrip.vue'),
			'utf8',
		)
		expect(Object.keys(CaseFollowStrip.props)).toContain('objectData')
		expect(strip).not.toContain('\n\t\tobject: {')
	})

	it('makes no call on mount', async () => {
		await mountStrip({ id: 'case-7', '@self': { watching: true } })

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).not.toHaveBeenCalled()
	})

	it('offers stopping when you already follow', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: true },
		})

		expect(wrapper.find('.bell-ring').exists()).toBe(true)
		expect(wrapper.text()).toContain('Stop following')
		// The state reaches a screen reader, not only the glyph.
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('true')
	})

	it('offers following when you do not', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: false },
		})

		expect(wrapper.find('.bell-outline').exists()).toBe(true)
		expect(wrapper.text()).toContain('Follow this case')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
	})

	it('treats an absent marker as not following', () => {
		// `@self.watching` is omitted entirely for an anonymous read, where
		// there is no "you" to answer for.
		expect(isFollowing({ id: 'x' })).toBe(false)
		expect(isFollowing({ id: 'x', '@self': {} })).toBe(false)
		expect(isFollowing(null)).toBe(false)
		// A control, so the three above cannot pass because the reader is broken.
		expect(isFollowing({ '@self': { watching: true } })).toBe(true)
	})
})

describe('the count beside the button', () => {
	it('says nothing at all when OpenRegister told this reader no count', async () => {
		// `@self.watcherCount` rides only for a reader who may update the case.
		// Absent means "not your business", and drawing it as 0 followers would
		// be a claim about the audience this reader was never told.
		expect(followerCountOf({ '@self': { watching: true } })).toBe(null)

		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: true },
		})

		expect(wrapper.find('[data-testid="case-follow-count"]').exists()).toBe(
			false,
		)
	})

	it('counts for a reader who was told one, and moves with the press', async () => {
		expect(followerCountOf({ '@self': { watcherCount: 0 } })).toBe(0)

		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: false, watcherCount: 2 },
		})

		expect(wrapper.find('[data-testid="case-follow-count"]').text()).toBe(
			'2 followers',
		)

		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="case-follow-count"]').text()).toBe(
			'3 followers',
		)
	})
})

describe('the two verbs', () => {
	it('follows with a PUT on the watch path', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: false },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/watch',
		)
	})

	it('stops with a DELETE on the same path', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: true },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()

		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/watch',
		)
	})

	it('puts the button back when the write is refused, and says what the server said', async () => {
		axios.put.mockRejectedValue({
			response: { data: { message: 'Not yours to follow.' } },
		})

		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { watching: false, watcherCount: 2 },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(mockShowError).toHaveBeenCalledWith('Not yours to follow.')
		expect(wrapper.find('.bell-outline').exists()).toBe(true)
		expect(wrapper.text()).toContain('Follow this case')
		expect(wrapper.find('[data-testid="case-follow-count"]').text()).toBe(
			'2 followers',
		)
	})

	it('reads the id from either shape a list row comes in', () => {
		expect(objectIdOf({ id: 'a' })).toBe('a')
		expect(objectIdOf({ '@self': { id: 'b' } })).toBe('b')
		expect(objectIdOf({})).toBe('')
	})
})

describe('the Followers section on the People tab', () => {
	it('lists who follows the case, from the watchers sub-resource', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ userId: 'anna', created: '2026-09-01T09:00:00+00:00' },
					{ userId: 'teamleider', displayName: 'Ilse Mulder' },
				],
				total: 2,
			},
		})

		const wrapper = await mountPanel()

		expect(axios.get.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/watchers',
		)

		const people = wrapper.findAll('[data-testid="case-followers-person"]')
		expect(people).toHaveLength(2)
		// The display name wins where the platform sends one, so the day
		// OpenRegister enriches the row this panel needs no change.
		expect(people[1].text()).toContain('Ilse Mulder')
		expect(people[1].text()).not.toContain('teamleider')
		// And the account name is what is left when it does not.
		expect(people[0].text()).toContain('anna')
	})

	it('draws a refusal apart from an empty list', async () => {
		axios.get.mockRejectedValue({ response: { status: 403 } })

		const refused = await mountPanel()
		expect(refused.text()).toContain('Only a handler of this case sees')

		// The control: the SAME panel over an empty answer says the opposite
		// thing, so the assertion above is about the 403 and not about the
		// panel drawing one message for every outcome.
		axios.get.mockResolvedValue({ data: { results: [], total: 0 } })
		const empty = await mountPanel()
		expect(empty.text()).toContain('Nobody follows this case yet')
	})

	it('says a failed read failed, rather than drawing nobody', async () => {
		axios.get.mockRejectedValue({ response: { status: 500 } })

		const wrapper = await mountPanel()

		expect(wrapper.text()).toContain('could not be read')
		expect(wrapper.text()).not.toContain('Nobody follows this case')
	})

	it('offers no way to unsubscribe a colleague', async () => {
		axios.get.mockResolvedValue({
			data: { results: [{ userId: 'anna' }], total: 1 },
		})

		const wrapper = await mountPanel()

		// Removing somebody else's subscription needs `manage` in OpenRegister,
		// and a button that 403s on press teaches nobody anything.
		expect(wrapper.findAll('button')).toHaveLength(0)
	})
})

describe('following is declared on the case page', () => {
	it('is a widget on the layout, beside the star and above the panels', () => {
		const follow = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-follow',
		)
		const star = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-favourite',
		)
		const panels = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-panels',
		)

		expect(follow, 'the follow strip is missing from the layout').toBeTruthy()
		expect(follow.gridY).toBe(star.gridY)
		expect(follow.gridY).toBeLessThan(panels.gridY)
	})

	it('declares widgets whose types the registry answers', () => {
		// A grid item falls through to CnDetailWidgetHost and a tab child
		// through CnTabsWidget; both resolve from `cnRegistry[widget.type]` and
		// render NOTHING, silently, when no key answers.
		const strip = caseDetail.config.widgets.find((w) => w.id === 'case-follow')

		expect(strip).toBeTruthy()
		expect(strip.type).toBe('case-follow')
		expect(registrySource).toContain("'case-follow': {")
		expect(registrySource).toContain('component: CaseFollowStrip,')
		expect(iconsSource).toContain(`\n\t${strip.icon},\n`)

		const people = caseDetail.config.widgets.find(
			(w) => w.id === 'case-people-panel',
		)
		const section = people.content.sections.find((s) => s.label === 'Followers')

		expect(section, 'the Followers section is missing').toBeTruthy()
		expect(section.widget.type).toBe('case-followers')
		expect(registrySource).toContain("'case-followers': {")
		expect(registrySource).toContain('component: CaseFollowersPanel,')
		expect(iconsSource).toContain(`\n\t${section.widget.icon},\n`)
	})

	it('carries the reason gate 29 asks a custom widget for', () => {
		const strip = registrySource.slice(
			registrySource.indexOf("'case-follow': {"),
			registrySource.indexOf("'case-followers': {"),
		)
		const panel = registrySource.slice(
			registrySource.indexOf("'case-followers': {"),
			registrySource.indexOf("'case-party-roles': {"),
		)

		expect(strip).toContain('@custom-widget-ratchet exclude')
		expect(panel).toContain('@custom-widget-ratchet exclude')
	})

	it('registers the bell icons the strip names', () => {
		// An icon named in a manifest and missing from the registry renders
		// NOTHING rather than a fallback glyph (hydra gate-60).
		expect(iconsSource).toContain('\n\tBellRing,\n')
		expect(iconsSource).toContain('\n\tBellOutline,\n')
	})
})

describe('the lens and the tile', () => {
	it('offers a Followed chip on Cases over the platform lens', () => {
		const chip = page('Cases').config.quickFilters.find(
			(c) => c.label === 'Followed',
		)

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
		const tile = home.config.widgets.find((w) => w.id === 'followed-cases')

		expect(tile).toBeTruthy()
		expect(tile.content.source.filter._watching).toBe(true)
		// The tile and the chip have to be the same lens, or a handler reads
		// two different sets of the cases they follow on two pages.
		expect(tile.content.viewAllRoute.query._watching).toBe('true')
		expect(tile.content.rowRoute).toBe('CaseDetail')
		expect(
			home.config.layout.some((c) => c.widgetId === 'followed-cases'),
			'followed-cases has no cell on the My Work grid',
		).toBe(true)
	})

	it('fills its rows on both pages: no cell overlaps another', () => {
		for (const id of ['CaseDetail', 'MyWorkHome']) {
			const grid = new Map()
			for (const cell of page(id).config.layout) {
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
