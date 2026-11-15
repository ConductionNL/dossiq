// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The first-run tab reads the setup status, lists readiness and names the
 * tour steps that point at a missing screen.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, template: '<div><slot /></div>' })
	return { NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }
})

const { default: FirstRunTab } = await import('../../src/views/settings/tabs/FirstRunTab.vue')

describe('FirstRunTab', () => {
	beforeEach(() => {
		mockGet.mockReset()
	})

	it('lists readiness, links a screen and marks done and open items', async () => {
		mockGet.mockResolvedValue({
			data: {
				readiness: [
					{ id: 'a', title: 'Case types', body: 'Add one', screen: '/apps/dossiq/#/case-types', done: true },
					{ id: 'b', title: 'Mail', body: 'Connect', done: false, failure: 'timeout' },
				],
				tourSteps: [{ step: 'intro', surface: 'dashboard', state: 'ok' }],
			},
		})
		const wrapper = mount(FirstRunTab)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/setup/status')
		const items = wrapper.findAll('.first-run-tab__item')
		expect(items).toHaveLength(2)
		expect(items[0].find('a').attributes('href')).toBe('/apps/dossiq/#/case-types')
		expect(items[0].find('.first-run-tab__state').classes()).toContain('is-done')
		expect(items[0].find('.first-run-tab__state').text()).toBe('Done')
		expect(items[1].find('a').exists()).toBe(false)
		expect(items[1].find('.first-run-tab__state').text()).toBe('Still open')
		expect(items[1].find('.first-run-tab__failure').text()).toBe('This could not be read: timeout')
		expect(wrapper.find('[data-testid="first-run-broken-steps"]').exists()).toBe(false)
	})

	it('names only the missing tour steps', async () => {
		mockGet.mockResolvedValue({
			data: {
				readiness: [],
				tourSteps: [
					{ step: 'intro', surface: 'dashboard', state: 'ok' },
					{ step: 'mail', surface: 'inbox', state: 'missing' },
					{ step: 'old', surface: 'reports', state: 'missing' },
				],
			},
		})
		const wrapper = mount(FirstRunTab)
		await flushPromises()

		expect(wrapper.vm.brokenSteps.map((s) => s.step)).toEqual(['mail', 'old'])
		expect(wrapper.find('[data-testid="first-run-broken-steps"]').text())
			.toBe('These tour steps point at a screen that is gone, so they teach nobody: mail (inbox), old (reports)')
	})

	it('reads a malformed status as empty', async () => {
		mockGet.mockResolvedValue({ data: { readiness: 'x', tourSteps: null } })
		const wrapper = mount(FirstRunTab)
		await flushPromises()

		expect(wrapper.vm.readiness).toEqual([])
		expect(wrapper.vm.brokenSteps).toEqual([])
		expect(wrapper.vm.error).toBe('')
	})

	it('shows the error when the status cannot be read', async () => {
		mockGet.mockRejectedValue(new Error('500'))
		const wrapper = mount(FirstRunTab)
		await flushPromises()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('The first run status could not be read.')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('The first run status could not be read.')
		expect(wrapper.find('[data-testid="first-run-readiness"]').exists()).toBe(false)
	})
})
