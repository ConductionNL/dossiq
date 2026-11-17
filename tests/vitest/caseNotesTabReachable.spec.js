// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rendered Notes tab lists the case's notes and sends none of them away.
 *
 * The tab is the library's notes tab, wired by the manifest: dossiq ships no
 * wrapper any more. This mounts the real CnNotesTab with exactly the props the
 * manifest declares on `case-notes-panel`. Case notes stay in dossiq, stored
 * by OpenRegister as comments on the case (case-notes-stay-in-dossiq), so the
 * tab offers no action that sends a note to another register.
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

describe('the rendered notes tab keeps notes in dossiq', () => {
	beforeEach(() => {
		global.OC = { currentUser: 'admin' }
		global.fetch = vi.fn(() => Promise.resolve({
			ok: true,
			status: 200,
			json: () => Promise.resolve({ results: [SOMEONE_ELSES_NOTE] }),
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

	it('lists the case note it read from OpenRegister', async () => {
		const wrapper = await mountTab()
		expect(wrapper.text()).toContain('Gebeld met de aanvrager')
		wrapper.unmount()
	})

	it('offers no action that sends a note to another register', async () => {
		const wrapper = await mountTab()
		expect(notesPanel.props.noteActions).toBeUndefined()
		expect(wrapper.find('[data-testid^="cn-note-action-"]').exists()).toBe(false)
		expect(global.fetch.mock.calls.some(([url]) => String(url).includes('/notes/push'))).toBe(false)
		wrapper.unmount()
	})
})

describe('the case notes add form', () => {
	it('waits behind "Add note", as DqZaakEmail draws it (nextcloud-vue notes-tab-composer-button)', () => {
		expect(notesPanel.props.composer).toBe('button')
	})
})
