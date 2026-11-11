// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Duplicate and Start-a-new-version dialogs post, land on what they
 * made, and say what went wrong when they cannot. The Publish and Import
 * dialogs share their refusal sentences.
 *
 * @spec openspec/specs/workflow-import-export/spec.md
 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { default: CaseTypeDuplicateDialog } =
	await import('../../src/dialogs/CaseTypeDuplicateDialog.vue')
const { default: CaseTypeNewVersionDialog } =
	await import('../../src/dialogs/CaseTypeNewVersionDialog.vue')
const { default: CaseTypePublishDialog } =
	await import('../../src/dialogs/CaseTypePublishDialog.vue')
const { default: CaseTypeImportDialog } =
	await import('../../src/dialogs/CaseTypeImportDialog.vue')

const REFUSALS = {
	signIn: 'Sign in again and retry.',
	forbidden: 'Your account may not do this.',
	missing: 'This case type no longer exists.',
	generic: 'That did not work. Try again, or ask an administrator.',
}

/**
 * Mount a dialog with a router double.
 *
 * @param {object} component The dialog.
 * @param {object} props     Its props.
 * @return {{wrapper: object, push: Function}} The wrapper and the router push spy.
 */
function mountDialog(component, props = {}) {
	const push = vi.fn()
	const wrapper = shallowMount(component, {
		props,
		global: {
			mocks: { $router: { push }, $route: { params: { id: 'route-id' } } },
		},
	})
	return { wrapper, push }
}

const LANDING = [
	{
		name: 'CaseTypeDuplicateDialog',
		component: CaseTypeDuplicateDialog,
		path: 'copy',
		lost: 'The copy was made but could not be opened.',
	},
	{
		name: 'CaseTypeNewVersionDialog',
		component: CaseTypeNewVersionDialog,
		path: 'new-version',
		lost: 'The draft was made but could not be opened.',
	},
]

describe.each(LANDING)('$name', ({ component, path, lost }) => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
	})

	it('posts for the given case type and lands on what it made', async () => {
		mockPost.mockResolvedValue({ data: { id: 'new-1' } })
		const { wrapper, push } = mountDialog(component, { caseTypeId: 'a b' })

		await wrapper.vm.confirm()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith(
			`/apps/dossiq/api/case-definitions/a%20b/${path}`,
		)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).toHaveBeenCalledWith({
			name: 'CaseTypeDetail',
			params: { id: 'new-1' },
		})
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('falls back to the route id and reads an id from @self', async () => {
		mockPost.mockResolvedValue({ data: { '@self': { id: 'new-2' } } })
		const { wrapper, push } = mountDialog(component)

		await wrapper.vm.confirm()

		expect(mockPost).toHaveBeenCalledWith(
			`/apps/dossiq/api/case-definitions/route-id/${path}`,
		)
		expect(push).toHaveBeenCalledWith({
			name: 'CaseTypeDetail',
			params: { id: 'new-2' },
		})
	})

	it('closes and says so when what it made cannot be found', async () => {
		mockPost.mockResolvedValue({ data: {} })
		const { wrapper, push } = mountDialog(component, { caseTypeId: 'x' })

		await wrapper.vm.confirm()

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(lost)
		expect(wrapper.vm.busy).toBe(false)
	})

	it.each([
		[401, REFUSALS.signIn],
		[403, REFUSALS.forbidden],
		[404, REFUSALS.missing],
		[500, REFUSALS.generic],
	])('a %s refusal stays open and says why', async (status, message) => {
		mockPost.mockRejectedValue({ response: { status } })
		const { wrapper, push } = mountDialog(component, { caseTypeId: 'x' })

		await wrapper.vm.confirm()

		expect(wrapper.emitted('close')).toBeUndefined()
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('refusal sentences shared by the publish and import dialogs', () => {
	it.each([
		['CaseTypePublishDialog', CaseTypePublishDialog],
		['CaseTypeImportDialog', CaseTypeImportDialog],
	])('%s carries the four refusal sentences', (name, component) => {
		mockGet.mockReturnValue(new Promise(() => {}))
		const { wrapper } = mountDialog(component, { caseTypeId: 'x' })

		expect(wrapper.vm.refusalMessages).toEqual(REFUSALS)
	})

	it('the publish dialog acts on the route id when given none', () => {
		mockGet.mockReturnValue(new Promise(() => {}))
		const { wrapper } = mountDialog(CaseTypePublishDialog)

		expect(wrapper.vm.id).toBe('route-id')
	})
})
