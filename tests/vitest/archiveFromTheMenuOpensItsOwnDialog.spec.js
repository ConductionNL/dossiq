// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqArchiveren draws archiving as its own dialog, "Zaak archiveren",
 * with the case on top. Choosing Archiveren in the lifecycle menu therefore
 * opens the case page's own Archive this case dialog (the `case-archive`
 * CnActionDialog, with its role verdict, checklist and checkbox) instead of
 * folding a form open inside the menu. Which acts do that is configuration:
 * the menu's `actDialogs` names, per act, the header action to open.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default
const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const headerAction = (id) => caseDetail.config.headerActions.find((action) => action.id === id)

/**
 * The menu, read, with a dispatcher spy.
 *
 * @param {object} props The menu's props.
 * @param {Function|null} dispatch What the page provides as cnDispatchAction.
 * @return {Promise<object>} The wrapper.
 */
async function menu(props, dispatch) {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/acts') ? { acts: [] } : url.endsWith('/lifecycle') ? {} : { transitions: [] },
	}))
	const wrapper = shallowMount(CaseLifecycleMenuDialog, {
		props: { caseId: 'case-1', ...props },
		global: {
			stubs: { CnDialog: { template: '<div><slot /><slot name="actions" /></div>' }, CnChoiceCards: true },
			provide: dispatch ? { cnDispatchAction: dispatch } : {},
		},
	})
	await flushPromises()
	return wrapper
}

describe('archiving from the lifecycle menu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('is configured on the menu action: Archiveren opens the case-archive dialog', () => {
		expect(headerAction('case-lifecycle-menu').props).toEqual({ actDialogs: { archive: 'case-archive' } })
		expect(headerAction('case-archive').target).toBe('CnActionDialog')
	})

	it('closes the menu and opens the archive dialog for this case when Archiveren is chosen', async () => {
		const dispatch = vi.fn()
		const wrapper = await menu({ actDialogs: { archive: 'case-archive' } }, dispatch)

		wrapper.vm.choose({ kind: 'ending', id: 'archive' })

		expect(dispatch).toHaveBeenCalledTimes(1)
		const [action, context] = dispatch.mock.calls[0]
		expect(action).toEqual(headerAction('case-archive'))
		expect(context).toEqual({ tokenCtx: { objectId: 'case-1' } })
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.chosen).toBeNull()
	})

	it('folds the form open in the menu as before for every other act, and when no page can open a dialog', async () => {
		const dispatch = vi.fn()
		const configured = await menu({ actDialogs: { archive: 'case-archive' } }, dispatch)
		configured.vm.choose({ kind: 'gesture', id: 'suspend' })
		expect(dispatch).not.toHaveBeenCalled()
		expect(configured.vm.chosen).toMatchObject({ id: 'suspend' })

		const alone = await menu({ actDialogs: { archive: 'case-archive' } }, null)
		alone.vm.choose({ kind: 'ending', id: 'archive' })
		expect(alone.vm.chosen).toMatchObject({ id: 'archive' })
		expect(alone.emitted('close')).toBeUndefined()
	})
})
