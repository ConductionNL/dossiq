// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the case page says about which of its panels hold something unseen.
 *
 * THE ORDER OF THE TWO CALLS ON MOUNT IS THE POINT OF THIS FILE. The GET
 * answers what was unread at the moment the page opened; the PUT then records
 * that the reader has seen the case. Reading after writing would answer about
 * a case the reader has just been recorded as having seen, and the strip would
 * be empty on every case, for ever, with nothing failing anywhere. So the
 * order is asserted rather than assumed.
 *
 * The second assertion that matters is that the mount-time PUT carries NO
 * sub-resource. OpenRegister stamps only the sub-resource a PUT names, which
 * is exactly what lets a document that arrived stay counted until the
 * documents are actually looked at. A PUT that stamped the panels would take
 * the badge with it the instant the case was opened.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseUnreadPanel from '../../src/components/case/CaseUnreadPanel.vue'

const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: (...a) => mockShowError(...a),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')

/**
 * Mount the strip and let both mount-time calls settle.
 *
 * @param {object} state What the read-state GET answers.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountStrip(state) {
	axios.get.mockResolvedValue({ data: state })
	axios.put.mockResolvedValue({ data: { unread: false, notificationsCleared: 0 } })

	const wrapper = mount(CaseUnreadPanel, {
		props: { objectId: 'case-7' },
		// `$attrs` carries the parent's own `onClick` in Vue 3, so the stub
		// binds it and does NOT re-emit: emitting as well fires the handler
		// twice and turns one gesture into two writes.
		global: { stubs: { NcButton: { template: '<button v-bind="$attrs"><slot /></button>' } } },
	})

	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await Promise.resolve()
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('the strip is declared on the case page', () => {
	it('is a widget on the layout, above the tab strip', () => {
		const strip = caseDetail.config.layout.find((l) => l.widgetId === 'case-unread')
		expect(strip, 'the unread strip is missing from the layout').toBeTruthy()

		const panels = caseDetail.config.layout.find((l) => l.widgetId === 'case-panels')
		expect(strip.gridY).toBeLessThan(panels.gridY)
		expect(strip.gridWidth).toBe(12)
	})

	it('declares a widget whose type the registry answers', () => {
		// A layout grid item falls through to CnDetailWidgetHost, which
		// resolves a renderer from `cnRegistry[widget.type]` and renders
		// NOTHING, silently, when no key answers. So the type is the key that
		// has to be there.
		const widget = caseDetail.config.widgets.find((w) => w.id === 'case-unread')
		expect(widget).toBeTruthy()
		expect(widget.type).toBe('case-unread')
		expect(registrySource).toContain("'case-unread': {")
		expect(registrySource).toContain('component: CaseUnreadPanel,')
		expect(iconsSource).toContain(`\n\t${widget.icon},\n`)
	})

	it('carries the reason gate 29 asks a custom widget for', () => {
		const entry = registrySource.slice(
			registrySource.indexOf("'case-unread': {"),
			registrySource.indexOf("'case-notes-pane': {"),
		)
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
	})
})

describe('opening a case reads its state, then records the visit', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
		axios.delete.mockReset()
		mockShowError.mockReset()
	})

	it('reads before it writes', async () => {
		const order = []
		axios.get.mockImplementation(() => {
			order.push('get')
			return Promise.resolve({ data: { unread: true, unreadCounts: { files: 2 }, subSeen: {}, lastSeenAt: null } })
		})
		axios.put.mockImplementation(() => {
			order.push('put')
			return Promise.resolve({ data: { unread: false, notificationsCleared: 1 } })
		})

		mount(CaseUnreadPanel, {
			props: { objectId: 'case-7' },
			global: { stubs: { NcButton: true } },
		})
		await Promise.resolve()
		await Promise.resolve()
		await Promise.resolve()

		expect(order).toEqual(['get', 'put'])
	})

	it('marks the CASE read and not its panels', async () => {
		await mountStrip({ unread: true, unreadCounts: { files: 2 }, subSeen: {}, lastSeenAt: null })

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/case-7/read-state',
		)
		expect(axios.put.mock.calls[0][1]).toEqual({})
	})

	it('names the panel that holds something new, and how much', async () => {
		const wrapper = await mountStrip({
			unread: true,
			unreadCounts: { files: 2 },
			subSeen: {},
			lastSeenAt: null,
		})

		expect(wrapper.find('[data-testid="case-unread"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-unread-files"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Files')
		expect(wrapper.text()).toContain('2')
	})

	it('says nothing at all on a case with nothing new', async () => {
		const wrapper = await mountStrip({
			unread: false,
			unreadCounts: { files: 0 },
			subSeen: {},
			lastSeenAt: '2026-09-14T10:00:00+00:00',
		})

		expect(wrapper.find('[data-testid="case-unread"]').exists()).toBe(false)
	})

	it('stays silent rather than erroring where the read state does not exist', async () => {
		// An instance whose OpenRegister does not carry `object-read-state` yet
		// answers 404. An error band across the top of every case page would be
		// a worse answer than no strip: nothing else on the page depends on it.
		axios.get.mockRejectedValue({ response: { status: 404, data: {} } })

		const wrapper = mount(CaseUnreadPanel, {
			props: { objectId: 'case-7' },
			global: { stubs: { NcButton: true } },
		})
		await Promise.resolve()
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="case-unread"]').exists()).toBe(false)
		expect(mockShowError).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})
})

describe('reading a panel clears its own count and nothing else', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
		axios.delete.mockReset()
		mockShowError.mockReset()
	})

	it('stamps the named sub-resource only', async () => {
		const wrapper = await mountStrip({
			unread: true,
			unreadCounts: { files: 2 },
			subSeen: {},
			lastSeenAt: null,
		})

		axios.put.mockClear()
		axios.put.mockResolvedValue({ data: { unread: false, notificationsCleared: 1 } })

		await wrapper.find('[data-testid="case-unread-files"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][1]).toEqual({ subResource: 'files' })
		expect(wrapper.find('[data-testid="case-unread-files"]').exists()).toBe(false)
	})

	it('puts the case back to unread on request', async () => {
		const wrapper = await mountStrip({
			unread: true,
			unreadCounts: { files: 1 },
			subSeen: {},
			lastSeenAt: null,
		})

		axios.delete.mockResolvedValue({ data: { unread: true } })

		await wrapper.find('[data-testid="case-unread-mark-unread"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/case-7/read-state',
		)
	})
})
