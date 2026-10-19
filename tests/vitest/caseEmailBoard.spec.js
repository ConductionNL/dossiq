// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The E-mail tab as DqZaakEmail draws it: the header and the template row
 * (template select, Concept openen uit sjabloon, Leeg concept), with the
 * linked mail and the notes as cards under it.
 *
 * @spec openspec/specs/case-email-integration/spec.md#requirement-the-system-shall-prefill-an-nc-mail-draft-from-a-template-it-shall-not-send-mail-itself
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const post = vi.fn(async () => ({ data: {} }))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => ({ data: String(url).includes('email-templates') ? { results: [] } : { caseType: 'ct-1' } })),
		post: (...args) => post(...args),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (u) => u }))

const { default: CaseEmailTab } = await import('../../src/views/cases/components/CaseEmailTab.vue')

/**
 * Mount the tab in the board variant.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountBoard() {
	const wrapper = mount(CaseEmailTab, {
		props: { caseId: 'case-1', content: { variant: 'board' } },
		global: {
			stubs: {
				NcSelect: { template: '<div class="select" />' },
				NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcEmptyContent: { template: '<div><slot /></div>' },
				NcLoadingIcon: { template: '<span />' },
			},
		},
	})
	await flushPromises()
	await wrapper.setData({ loading: false, mailNotInstalled: false })
	return wrapper
}

describe('CaseEmailTab, board variant', () => {
	it('draws the template row and no mail thread of its own', async () => {
		const wrapper = await mountBoard()
		expect(wrapper.find('[data-testid="case-email-template-row"]').exists()).toBe(true)
		expect(wrapper.find('.email-thread').exists()).toBe(false)
		// No template chosen: only the empty draft can open.
		expect(wrapper.find('[data-testid="case-email-draft-template"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="case-email-draft-blank"]').attributes('disabled')).toBeUndefined()
	})

	it('opens an empty draft whatever template was chosen', async () => {
		const wrapper = await mountBoard()
		await wrapper.setData({ selectedTemplate: { id: 'tpl-1', name: 'Ontvangstbevestiging' } })
		await wrapper.vm.composeBlank()
		const [url] = post.mock.calls.at(-1)
		expect(url).toContain('/email-templates/blank/draft')
		expect(wrapper.vm.selectedTemplate).toBe(null)
	})

	it('stands first on the simple profile E-mail tab, with the linked mail and the notes as cards', () => {
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
		const overlay = simple.pages.find((page) => page.id === 'CaseDetail')
		expect(overlay.configPatch.widgets['case-email-panel'].content).toEqual({ variant: 'board' })
		const tab = overlay.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'Email')
		expect(tab.widgetIds).toEqual(['case-email-panel', 'case-linked-mail', 'case-notes-panel'])
		const linked = overlay.configAppend.widgets.find((widget) => widget.id === 'case-linked-mail')
		expect(linked).toMatchObject({ type: 'integration', integrationId: 'email' })
	})
})
