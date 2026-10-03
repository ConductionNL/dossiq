// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A marker and an unread badge are different facts, and the page has to show
 * that.
 *
 * 🔴 THIS IS THE FILE THAT STOPS THE TWO COLLAPSING INTO ONE BADGE. Both point
 * at a panel of the case page, so the cheap thing to build is one "this panel
 * needs you" count over the pair. It would be wrong in a way nobody would
 * notice for months: the unread badge is per reader and goes because somebody
 * looked, the marker is about the world and goes when the work is done. A
 * handler who cannot tell them apart cannot tell a colleague's unopened note
 * from a document that failed its virus scan.
 *
 * So: the marker rows come from the CASE, the unread counts come from the
 * read-state endpoint, they are drawn by two different components in two
 * different strips, and the marker survives everything that clears the badge.
 *
 * 🔴 A MARKER NAMES A PANEL THE PAGE ACTUALLY RENDERS. A marker pointing at a
 * panel that is not in the tab strip sends a handler looking for a tab that
 * does not exist. The manifest is read here rather than described.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseAttentionPanel from '../../src/components/case/CaseAttentionPanel.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
const panelIds = caseDetail.config.widgets
	.find((w) => w.id === 'case-panels')
	.content.tabs.map((t) => t.widgetId)

const stubs = {
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: {
		props: ['value'],
		template:
			'<input v-bind="$attrs" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
	},
}

const NO_FLAG = { raised: false, flag: {}, history: [], raisings: 0, clearings: 0 }

/**
 * Mount the strip over a case carrying markers.
 *
 * @param {Array} markers What the case carries.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWithMarkers(markers) {
	axios.get.mockResolvedValue({ data: NO_FLAG })

	const wrapper = mount(CaseAttentionPanel, {
		props: { objectId: 'case-7', objectData: { attentionMarkers: markers } },
		global: { stubs },
	})

	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await Promise.resolve()
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('a marker names the panel where the work is', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('draws the overdue advice against the Work panel, with its reason', async () => {
		const wrapper = await mountWithMarkers([
			{
				marker: 'advice-request-overdue',
				tab: 'case-work-panel',
				reason: '1 advice request(s) are past the date they were asked for.',
				raisedAt: '2026-06-01T09:00:00+00:00',
			},
		])

		const marker = wrapper.find(
			'[data-testid="case-marker-advice-request-overdue"]',
		)
		expect(marker.exists()).toBe(true)
		expect(marker.attributes('data-tab')).toBe('case-work-panel')
		expect(marker.text()).toContain('Work')
		expect(marker.text()).toContain('past the date they were asked for')
	})

	it('says nothing at all on a case carrying no marker', async () => {
		const wrapper = await mountWithMarkers([])

		expect(wrapper.find('[data-testid="case-attention-markers"]').exists()).toBe(
			false,
		)
	})

	it('draws one row per marker, each naming its own panel', async () => {
		const wrapper = await mountWithMarkers([
			{
				marker: 'term-exceeded',
				tab: 'case-data-panel',
				reason: 'a',
				raisedAt: '2026-06-01T09:00:00+00:00',
			},
			{
				marker: 'advice-request-overdue',
				tab: 'case-work-panel',
				reason: 'b',
				raisedAt: '2026-06-02T09:00:00+00:00',
			},
		])

		const rows = wrapper.findAll('[data-testid="case-attention-markers"] li')
		expect(rows).toHaveLength(2)
		expect(rows.map((r) => r.attributes('data-tab'))).toEqual([
			'case-data-panel',
			'case-work-panel',
		])
	})

	it('shows the marker id rather than nothing when a panel has no label', async () => {
		// A marker nobody can label is still a marker somebody should see. The
		// alternative is a blank row, which reads as a rendering bug.
		const wrapper = await mountWithMarkers([
			{
				marker: 'invented',
				tab: 'case-something-new',
				reason: 'a reason',
				raisedAt: '2026-06-01T09:00:00+00:00',
			},
		])

		expect(
			wrapper.find('[data-testid="case-marker-invented"]').text(),
		).toContain('case-something-new')
	})
})

describe('the marker is not the unread badge', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('reads its markers off the case and makes no read-state call', async () => {
		// The unread strip reads and then writes the read state on mount. This
		// strip must not: writing one would clear the other panel's badge as a
		// side effect of drawing a marker.
		const wrapper = await mountWithMarkers([
			{
				marker: 'advice-request-overdue',
				tab: 'case-work-panel',
				reason: 'a',
				raisedAt: '2026-06-01T09:00:00+00:00',
			},
		])

		expect(
			wrapper
				.find('[data-testid="case-marker-advice-request-overdue"]')
				.exists(),
		).toBe(true)
		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.delete).not.toHaveBeenCalled()

		const read = axios.get.mock.calls.map((c) => String(c[0]))
		expect(read.some((url) => url.includes('read-state'))).toBe(false)
	})

	it('offers no gesture that dismisses a marker', async () => {
		// Clearing is doing the work. A dismissal button would be the per-user
		// unread badge again, wearing the marker's clothes.
		const wrapper = await mountWithMarkers([
			{
				marker: 'advice-request-overdue',
				tab: 'case-work-panel',
				reason: 'a',
				raisedAt: '2026-06-01T09:00:00+00:00',
			},
		])

		const marker = wrapper.find(
			'[data-testid="case-marker-advice-request-overdue"]',
		)
		expect(marker.findAll('button')).toHaveLength(0)
	})

	it('is drawn in its own strip, below the one the unread counts use', () => {
		// Both strips share ONE grid row now (CaseBannerStack) because each is a
		// root v-if and an own row stayed reserved when empty. They are still two
		// SEPARATE components in a fixed order — that is what this asserts, and it
		// is the point: a marker survives opening the panel it names, an unread
		// badge does not, so they must not merge into one strip.
		const stack = fs.readFileSync(
			path.join(ROOT, 'src', 'components', 'case', 'CaseBannerStack.vue'),
			'utf8',
		)
		const unreadAt = stack.indexOf('<CaseUnreadPanel')
		const attentionAt = stack.indexOf('<CaseAttentionPanel')

		expect(unreadAt).toBeGreaterThan(-1)
		expect(attentionAt).toBeGreaterThan(-1)
		expect(unreadAt).toBeLessThan(attentionAt)
	})
})

describe('the declared panels are panels the page renders', () => {
	it('offers no panel in the schema the tab strip does not carry', () => {
		const fragment = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'38-markers-and-assessments.json',
				),
				'utf8',
			),
		)
		const declared =
			fragment.components.schemas.caseType.properties.attentionMarkers.items
				.properties.tab.enum

		expect(declared.filter((id) => panelIds.includes(id) === false)).toEqual([])
	})

	it('offers exactly one clearing, and it is not a dismissal', () => {
		const fragment = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'38-markers-and-assessments.json',
				),
				'utf8',
			),
		)
		const clearing =
			fragment.components.schemas.caseType.properties.attentionMarkers.items
				.properties.clearWhen

		expect(clearing.enum).toEqual(['condition-no-longer-true'])
	})
})
