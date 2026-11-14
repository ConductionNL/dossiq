// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The note push is reachable from the rendered Notes tab.
 *
 * The tab is the library's notes tab, wired by the manifest: dossiq ships no
 * wrapper any more. This mounts the real CnNotesTab with exactly the props the
 * manifest declares on `case-notes-panel`, so a manifest that stopped passing
 * the action, or a library that stopped reading it, fails here.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CnNotesTab from '@conduction/nextcloud-vue/src/components/CnObjectSidebar/CnNotesTab.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const notesPanel = caseDetail.config.widgets.find((widget) => widget.id === 'case-notes-panel')

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
		global.fetch = vi.fn((url) => Promise.resolve({
			ok: true,
			status: 200,
			json: () => Promise.resolve(String(url).includes('/notes/push')
				? { outcome: 'sent', reason: '' }
				: { results: [SOMEONE_ELSES_NOTE] }),
		}))
	})

	afterEach(() => {
		delete global.fetch
		delete global.OC
	})

	/**
	 * Mount the library tab with the manifest's props, one note listed.
	 *
	 * @return {Promise<object>} The settled wrapper.
	 */
	async function mountTab() {
		const wrapper = mount(CnNotesTab, {
			props: { objectId: 'case-1', register: 'dossiq', schema: 'case', ...notesPanel.props },
			global: { stubs: { NcRichContenteditable: true } },
		})
		await flushPromises()
		await flushPromises()
		return wrapper
	}

	it("renders a button carrying dossiq's own label", async () => {
		const wrapper = await mountTab()
		const button = wrapper.find('[data-testid="cn-note-action-push-to-neighbouring-register"]')
		expect(button.exists(), 'the manifest stopped passing the note action, or the library stopped reading it').toBe(true)
		expect(button.attributes('aria-label')).toBe('Send to the neighbouring register')
		wrapper.unmount()
	})

	it('pressing it sends THAT note to the push endpoint', async () => {
		const wrapper = await mountTab()
		await wrapper.find('[data-testid="cn-note-action-push-to-neighbouring-register"]').trigger('click')
		await flushPromises()
		const push = global.fetch.mock.calls.find(([url]) => String(url).includes('/notes/push'))
		expect(String(push[0])).toContain('/apps/dossiq/api/cases/case-1/notes/push')
		expect(JSON.parse(push[1].body)).toEqual({ note: SOMEONE_ELSES_NOTE })
		wrapper.unmount()
	})
})

describe('the case notes add form', () => {
	it('waits behind "Add note", as DqZaakEmail draws it (nextcloud-vue notes-tab-composer-button)', () => {
		expect(notesPanel.props.composer).toBe('button')
	})
})
