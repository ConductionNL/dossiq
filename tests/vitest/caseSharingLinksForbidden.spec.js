// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A refusal to list a case's access links is an answer, not a failure.
 *
 * The sharing tab loads on every case detail — pane or page, and whether or
 * not the reader opens the tab — so anything it says on mount, it says about
 * every case somebody looks at. Reading the links needs the case assigned to
 * you, so a handler browsing a colleague's case got 403 and a red "Could not
 * load the links on this case" toast on each one.
 *
 * Asserted per status rather than per call, because the two answers arrive the
 * same way: `loadLinks` sees a rejected promise either way, and a 403 read as a
 * failure is indistinguishable from a real one unless the status is kept.
 *
 * The other half of this lives in the backend — `CaseAccessPolicy` documented
 * an admin bullet it never checked, so an admin was refused on their own
 * instance. See `tests/Unit/Service/Sharing/CaseAccessPolicyAdminTest.php`.
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseSharingTab from '../../src/views/cases/components/CaseSharingTab.vue'

const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: (...a) => mockShowError(...a),
}))

const FORBIDDEN = 'links forbidden'
const NOTE = '[data-testid="case-sharing-links-forbidden"]'

/**
 * An axios rejection carrying an HTTP status, shaped as axios shapes it.
 *
 * @param {number} status The HTTP status.
 * @return {Error} The rejection.
 */
function httpError(status) {
	const err = new Error(`HTTP ${status}`)
	err.response = { status, data: { success: false } }
	return err
}

/**
 * Mount the tab and let its five mount-time reads settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountTab() {
	const wrapper = mount(CaseSharingTab, {
		props: { objectId: 'case-1' },
		global: {
			stubs: {
				ShareTab: { template: '<div class="share-tab" />' },
				CreateAccessLinkDialog: true,
				CreateShareDialog: true,
				CaseTransferDialog: true,
				CreateFederatedShareDialog: true,
				FederatedActivityPanel: true,
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
			},
		},
	})

	for (let i = 0; i < 5; i++) {
		await wrapper.vm.$nextTick()
	}

	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	// Everything the tab reads on mount succeeds; each test then fails only the
	// links read, so nothing else can account for a toast.
	axios.get.mockResolvedValue({ data: { results: [] } })
})

describe('the links read is refused', () => {
	it('says nothing in a notification', async () => {
		axios.get.mockImplementation((url) =>
			url.includes('/access-links/case/')
				? Promise.reject(httpError(403))
				: Promise.resolve({ data: { results: [] } }),
		)

		const wrapper = await mountTab()

		expect(mockShowError).not.toHaveBeenCalled()
		expect(wrapper.vm.linksForbidden).toBe(true)
	})

	it('says it in the tab, where it is about the case being read', async () => {
		axios.get.mockImplementation((url) =>
			url.includes('/access-links/case/')
				? Promise.reject(httpError(403))
				: Promise.resolve({ data: { results: [] } }),
		)

		const wrapper = await mountTab()

		expect(wrapper.find(NOTE).exists()).toBe(true)
		expect(wrapper.find(NOTE).text()).toContain('assigned')
	})
})

describe('the links read actually fails', () => {
	it.each([500, 502, 404])('still reports a %s', async (status) => {
		axios.get.mockImplementation((url) =>
			url.includes('/access-links/case/')
				? Promise.reject(httpError(status))
				: Promise.resolve({ data: { results: [] } }),
		)

		const wrapper = await mountTab()

		expect(mockShowError).toHaveBeenCalled()
		expect(wrapper.vm.linksForbidden).toBe(false)
		expect(wrapper.find(NOTE).exists()).toBe(false)
	})

	it('reports a rejection that carries no response at all', async () => {
		// A request that never arrived: no `err.response`, so the status read
		// must not throw on the way to the toast.
		axios.get.mockImplementation((url) =>
			url.includes('/access-links/case/')
				? Promise.reject(new Error('Network Error'))
				: Promise.resolve({ data: { results: [] } }),
		)

		const wrapper = await mountTab()

		expect(mockShowError).toHaveBeenCalled()
		expect(wrapper.vm.linksForbidden).toBe(false)
	})
})

describe('the links read succeeds', () => {
	it('shows no refusal note and keeps the rows', async () => {
		axios.get.mockImplementation((url) =>
			url.includes('/access-links/case/')
				? Promise.resolve({
						data: { results: [{ id: 1, label: FORBIDDEN }] },
					})
				: Promise.resolve({ data: { results: [] } }),
		)

		const wrapper = await mountTab()

		expect(mockShowError).not.toHaveBeenCalled()
		expect(wrapper.find(NOTE).exists()).toBe(false)
		expect(wrapper.vm.links).toHaveLength(1)
	})
})
