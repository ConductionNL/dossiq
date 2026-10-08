// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The push endpoint is reachable from a rendered page, not just from a stub.
 *
 * 🔴 WHY THIS FILE EXISTS BESIDE caseNotesTab.spec.js. That suite mounts a
 * stub leaf, so it proves dossiq HANDS an action over and POSTs when the
 * event comes back. It cannot prove the shared library does anything with
 * it: a stub accepts any prop, including one nothing reads, which is the
 * exact failure this whole change came to end.
 *
 * So this one mounts the REAL published `CnNotesTab` out of node_modules,
 * through dossiq's own wrapper, and drives it the way a person does: find
 * the button by the label dossiq wrote, press it, and watch the request
 * leave. If a future library release drops `noteActions`, this reds and the
 * stub suite stays green, which is the whole point of having both.
 *
 * `NcRichContenteditable` is stubbed because its Tribute mention plugin
 * cannot attach under jsdom. It is the note COMPOSER, not the notes list,
 * and nothing here touches it.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CnNotesTab from '@conduction/nextcloud-vue/src/components/CnObjectSidebar/CnNotesTab.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
}))

// The one seam this file replaces: the integration registry is populated at
// library bootstrap, which no unit test runs. The component it hands back is
// the real one, imported above.
vi.mock('../../src/integrations/leafTabs.js', () => ({
	leafTab: (id) => (id === 'notes' ? CnNotesTab : undefined),
}))

const { default: CaseNotesTab } =
	await import('../../src/views/cases/components/CaseNotesTab.vue')

// A note somebody ELSE wrote, deliberately: the library shows its own edit
// and delete actions only on your own notes, so a colleague's note is the
// one that proves an app action reaches every row.
const SOMEONE_ELSES_NOTE = {
	id: 42,
	message: 'Gebeld met de aanvrager',
	actorId: 'anna',
	actorDisplayName: 'Anna Bakker',
	versionCount: 0,
}

describe('the note push is reachable from the rendered notes tab', () => {
	beforeEach(() => {
		global.OC = { currentUser: 'admin' }
		global.fetch = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			json: () => Promise.resolve({ results: [SOMEONE_ELSES_NOTE] }),
		})
		axios.post.mockReset()
		axios.post.mockResolvedValue({
			data: { outcome: 'sent', reason: '', caseRecord: 'written' },
		})
	})

	afterEach(() => {
		delete global.fetch
		delete global.OC
	})

	/**
	 * Mount dossiq's tab over the real library component, with one note listed.
	 *
	 * @return {Promise<object>} The settled wrapper.
	 */
	async function mountTab() {
		const wrapper = mount(CaseNotesTab, {
			props: { objectId: 'case-1', register: 'dossiq', schema: 'case' },
			global: { stubs: { NcRichContenteditable: true } },
		})
		await flushPromises()
		await flushPromises()
		return wrapper
	}

	it("renders a button carrying dossiq's own label", async () => {
		const wrapper = await mountTab()
		const button = wrapper.find(
			'[data-testid="cn-note-action-push-to-neighbouring-register"]',
		)
		expect(
			button.exists(),
			"the real CnNotesTab rendered no button for dossiq's note action, so either dossiq stopped passing noteActions or the library stopped reading it, and the push endpoint is unreachable again",
		).toBe(true)
		// The ACCESSIBLE NAME, not the visible text. A lone action collapses
		// to an icon button whose label lives in aria-label and title, so
		// asserting `.text()` would red on a button a screen reader announces
		// perfectly well.
		expect(button.attributes('aria-label')).toBe(
			'Send to the neighbouring register',
		)
		wrapper.unmount()
	})

	it('pressing it sends THAT note to the push endpoint', async () => {
		const wrapper = await mountTab()
		await wrapper
			.find('[data-testid="cn-note-action-push-to-neighbouring-register"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-1/notes/push',
			{ note: SOMEONE_ELSES_NOTE },
		)
		wrapper.unmount()
	})
})
