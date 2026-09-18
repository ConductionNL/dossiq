// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The per-reader star on a case: the two verbs, the strip and the lenses.
 *
 * THE VERB IS THE POINT OF THIS FILE. Starring is a PUT and unstarring is a
 * DELETE on the same path, and every declarative affordance in the library
 * writes with ONE method. A strip that PUT in both directions would look
 * exactly right, would report success, and would leave a star nobody could
 * take off. So the method is asserted per direction rather than the call
 * count.
 *
 * The second thing asserted here is that the star is read off the object and
 * not fetched. `@self.favourite` rides every object read, so the strip must
 * make NO call on mount; a strip that fetched would cost one call per case
 * page and would still be wrong on a list row, where there is no endpoint to
 * fetch per row at all.
 *
 * @spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseFavouriteStrip from '../../src/components/case/CaseFavouriteStrip.vue'
import { isFavourite, objectIdOf } from '../../src/services/favouriteApi.js'
import { toggleCaseFavourite } from '../../src/utils/caseFavourite.js'

const mockShowError = vi.fn()
const mockShowSuccess = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

const page = (id) => manifest.pages.find((p) => p.id === id)
const caseDetail = page('CaseDetail')

/**
 * Mount the strip over a case payload.
 *
 * @param {object} object The case as the page holds it.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountStrip(objectData) {
	const wrapper = mount(CaseFavouriteStrip, {
		// `objectData`, spelled the way `CnDetailWidgetHost.rendererProps()`
		// spells it. A test that mounted with `object` would pass while the
		// real page handed the widget nothing and painted every star empty.
		props: { objectId: 'case-7', objectData },
		global: {
			stubs: {
				// The stub declares `pressed` because the real NcButton does, and
				// renders `aria-pressed` from it the way the real one does. A
				// stub that only spread `$attrs` would drop a declared prop and
				// the state assertion would then be about the stub.
				NcButton: {
					props: { pressed: { type: Boolean, default: null } },
					template:
						'<button v-bind="$attrs" :aria-pressed="pressed === null ? null : String(pressed)">'
						+ '<slot name="icon" /><slot /></button>',
				},
				Star: { template: '<i class="star-filled" />' },
				StarOutline: { template: '<i class="star-outline" />' },
			},
		},
	})

	await wrapper.vm.$nextTick()

	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.put.mockResolvedValue({ data: { favourite: true } })
	axios.delete.mockResolvedValue({ data: { favourite: false } })
})

describe('the star reads off the object it was given', () => {
	it('declares the prop name the detail widget host actually binds', () => {
		// `CnDetailWidgetHost.rendererProps()` hands a registry widget
		// `objectData`. A prop called `object` is never bound, arrives null,
		// and the star paints empty on every case the reader has starred, with
		// nothing failing anywhere. So the name is read off the library rather
		// than remembered.
		const host = fs.readFileSync(
			path.join(
				ROOT,
				'node_modules/@conduction/nextcloud-vue/src/components/CnDetailWidgetHost/CnDetailWidgetHost.vue',
			),
			'utf8',
		)
		expect(host).toContain('objectData: this.object,')

		const strip = fs.readFileSync(
			path.join(ROOT, 'src/components/case/CaseFavouriteStrip.vue'),
			'utf8',
		)
		expect(Object.keys(CaseFavouriteStrip.props)).toContain('objectData')
		expect(strip).not.toContain('\n\t\tobject: {')
	})

	it('makes no call on mount', async () => {
		await mountStrip({ id: 'case-7', '@self': { favourite: true } })

		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).not.toHaveBeenCalled()
	})

	it('renders the filled star and the taking-off label when starred', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { favourite: true },
		})

		expect(wrapper.find('.star-filled').exists()).toBe(true)
		expect(wrapper.text()).toContain('Remove from favourites')
		// The state reaches a screen reader, not only the glyph.
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('true')
	})

	it('renders the outline and the adding label when not starred', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { favourite: false },
		})

		expect(wrapper.find('.star-outline').exists()).toBe(true)
		expect(wrapper.text()).toContain('Add to favourites')
		expect(wrapper.find('button').attributes('aria-pressed')).toBe('false')
	})

	it('treats an absent flag as not starred', () => {
		// `@self.favourite` is omitted entirely for an anonymous read, where
		// there is no "you" to answer for. Reading absence as starred would
		// paint every row on a public page.
		expect(isFavourite({ id: 'x' })).toBe(false)
		expect(isFavourite({ id: 'x', '@self': {} })).toBe(false)
		expect(isFavourite(null)).toBe(false)
		// A control, so the three above cannot pass because the reader is broken.
		expect(isFavourite({ '@self': { favourite: true } })).toBe(true)
	})
})

describe('the two verbs', () => {
	it('stars with a PUT on the favourite path', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { favourite: false },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/favourite',
		)
	})

	it('unstars with a DELETE on the same path', async () => {
		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { favourite: true },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()

		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-7/favourite',
		)
	})

	it('puts the star back when the write is refused, and says what the server said', async () => {
		axios.put.mockRejectedValue({
			response: { data: { message: 'Not yours to star.' } },
		})

		const wrapper = await mountStrip({
			id: 'case-7',
			'@self': { favourite: false },
		})
		await wrapper.find('button').trigger('click')
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(mockShowError).toHaveBeenCalledWith('Not yours to star.')
		expect(wrapper.find('.star-outline').exists()).toBe(true)
		expect(wrapper.text()).toContain('Add to favourites')
	})
})

describe('the row action', () => {
	it('stars a row that is not starred', async () => {
		await toggleCaseFavourite({
			item: { id: 'case-9', '@self': { favourite: false } },
		})

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.delete).not.toHaveBeenCalled()
		expect(mockShowSuccess).toHaveBeenCalledWith('Added to your favourites.')
	})

	it('unstars a row that is starred', async () => {
		await toggleCaseFavourite({
			item: { id: 'case-9', '@self': { favourite: true } },
		})

		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.put).not.toHaveBeenCalled()
		expect(mockShowSuccess).toHaveBeenCalledWith('Removed from your favourites.')
	})

	it('does nothing at all on a row that carries no id', async () => {
		await toggleCaseFavourite({ item: {} })

		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).not.toHaveBeenCalled()
		// A control: the same call with an id does write, so the silence above
		// is the guard rather than a broken handler.
		await toggleCaseFavourite({ item: { id: 'case-9' } })
		expect(axios.put).toHaveBeenCalledTimes(1)
	})

	it('reads the id from either shape a list row comes in', () => {
		expect(objectIdOf({ id: 'a' })).toBe('a')
		expect(objectIdOf({ '@self': { id: 'b' } })).toBe('b')
		expect(objectIdOf({})).toBe('')
	})
})

describe('the star is declared on the case page', () => {
	it('rides the banner row, above the panels', () => {
		// The four strips share ONE grid row now (case-banner-stack): three of
		// them are a root v-if, so four rows reserved three empty ones on an
		// ordinary case. Their ORDER is the component's, asserted below.
		const banners = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-banner-stack',
		)
		const panels = caseDetail.config.layout.find(
			(l) => l.widgetId === 'case-panels',
		)

		expect(banners, 'the banner row is missing from the layout').toBeTruthy()
		expect(banners.gridY).toBeLessThan(panels.gridY)
		// The row is only as tall as whatever rendered; without this an empty
		// stack costs its whole authored height back again.
		expect(banners.sizeToContent).toBe(true)
	})

	it('is the first working strip in the banner stack, under the archive line', () => {
		// Only the archived strip precedes it, because that one changes how
		// everything under it should be read.
		const stack = fs.readFileSync(
			path.join(ROOT, 'src', 'components', 'case', 'CaseBannerStack.vue'),
			'utf8',
		)
		const order = ['CaseArchivedStrip', 'CaseFavouriteStrip', 'CaseUnreadPanel', 'CaseStatusDeclarationPanel', 'CaseAttentionPanel']
			.map((c) => stack.indexOf(`<${c}`))

		expect(order.every((i) => i > -1)).toBe(true)
		expect([...order].sort((a, b) => a - b)).toEqual(order)
	})

	it('declares a widget whose type the registry answers', () => {
		// A layout grid item falls through to CnDetailWidgetHost, which resolves
		// a renderer from `cnRegistry[widget.type]` and renders NOTHING,
		// silently, when no key answers.
		const widget = caseDetail.config.widgets.find(
			(w) => w.id === 'case-banner-stack',
		)

		expect(widget).toBeTruthy()
		expect(widget.type).toBe('case-banner-stack')
		expect(registrySource).toContain("'case-banner-stack': {")
		// The star's own type stays registered: still a valid placement, just
		// not the one this page uses.
		expect(registrySource).toContain("'case-favourite': {")
		expect(registrySource).toContain('component: CaseFavouriteStrip,')
		expect(iconsSource).toContain(`\n\t${widget.icon},\n`)
	})

	it('carries the reason gate 29 asks a custom widget for', () => {
		const entry = registrySource.slice(
			registrySource.indexOf("'case-favourite': {"),
			registrySource.indexOf("'case-unread': {"),
		)

		expect(entry).toContain('@custom-widget-ratchet exclude')
	})

	it('registers the star icons the manifest and the strip name', () => {
		// An icon named in a manifest and missing from the registry renders
		// NOTHING rather than a fallback glyph (hydra gate-60).
		expect(iconsSource).toContain('\n\tStar,\n')
		expect(iconsSource).toContain('\n\tStarOutline,\n')
	})
})

describe('the two lenses', () => {
	it('offers a Favourites chip and a Recently opened chip on Cases', () => {
		const chips = page('Cases').config.quickFilters
		const labels = chips.map((c) => c.label)

		expect(labels).toContain('Favourites')
		expect(labels).toContain('Recently opened')

		// The lens keys are OpenRegister's and are resolved INSIDE the query,
		// the way `_unread` is. A chip spelling them any other way would filter
		// on a property the `case` schema does not carry, which the objects
		// endpoint answers as the empty set rather than as an error.
		expect(chips.find((c) => c.label === 'Favourites').filter._favourite).toBe(
			true,
		)
		expect(chips.find((c) => c.label === 'Recently opened').filter._recent).toBe(
			true,
		)
	})

	it('declares no order on the Recently opened chip', () => {
		// `_recent` carries its own order, last view descending. An `_order`
		// declared beside it would override the one thing the lens is for and
		// the chip would answer a different question from its own name.
		const recent = page('Cases').config.quickFilters.find(
			(c) => c.label === 'Recently opened',
		)

		expect(recent.filter._order).toBeUndefined()
		expect(recent.order).toBeUndefined()
	})

	it('offers the row action on every case list that carries the read actions', () => {
		for (const id of ['Cases', 'Queue']) {
			const actions = page(id).config.actions.map((a) => a.id)
			expect(actions, `${id} is missing the favourite row action`).toContain(
				'favourite',
			)

			const action = page(id).config.actions.find((a) => a.id === 'favourite')
			// `api-call` is not in the row dispatcher's vocabulary, so a
			// declarative entry would render a menu item that does nothing.
			expect(action.type).toBe('handler')
			expect(action.handler).toBe('toggleCaseFavourite')
		}

		expect(registrySource).toMatch(/\n\ttoggleCaseFavourite: \{\n\t\tkind: 'handler',\n\t\thandler: toggleCaseFavourite,\n/)
	})

	it('puts both lenses on the dashboard, each pointing at its own chip', () => {
		const dashboard = page('Dashboard')
		const tiles = dashboard.config.widgets

		const favourites = tiles.find((w) => w.id === 'favourite-cases')
		const recent = tiles.find((w) => w.id === 'recent-cases')

		expect(favourites, 'the Favourites tile is missing').toBeTruthy()
		expect(recent, 'the Recently opened tile is missing').toBeTruthy()
		expect(favourites.content.source.filter._favourite).toBe(true)
		expect(recent.content.source.filter._recent).toBe(true)

		// The tile and the chip have to be the same lens, or a handler reads two
		// different sets of their own favourites on two pages.
		expect(favourites.content.viewAllRoute.query._favourite).toBe('true')
		expect(recent.content.viewAllRoute.query._recent).toBe('true')

		for (const id of ['favourite-cases', 'recent-cases']) {
			expect(
				dashboard.config.layout.some((c) => c.widgetId === id),
				`${id} has no cell on the dashboard grid`,
			).toBe(true)
		}
	})

	it('fills its rows on both pages: no cell overlaps another', () => {
		for (const id of ['CaseDetail', 'Dashboard']) {
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
