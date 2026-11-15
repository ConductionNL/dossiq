// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The shipped-configuration section: it lists the shipped rows for the chosen
 * schema, counts the register's objects when none are shipped, shows a read
 * error, and adopts a newer version (confirming the loss of a local change).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = { listShipped: vi.fn(), countObjects: vi.fn(), adoptShipped: vi.fn() }
const dialogs = { showError: vi.fn(), showSuccess: vi.fn() }

vi.mock('../../src/services/starterApi.js', () => api)
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/utils/starterStates.js', () => ({
	shippedLabel: (row) => `label:${row.state}`,
	hasUpdate: (row) => Boolean(row.updateAvailable),
	adoptionLosesLocalChange: (row) => row.state === 'changed',
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m) : text) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => null },
	NcSelect: { name: 'NcSelect', render: () => null },
}))

const { default: ShippedConfiguration } = await import('../../src/views/settings/ShippedConfiguration.vue')

async function mounted() {
	const wrapper = shallowMount(ShippedConfiguration)
	await flushPromises()
	return wrapper
}

describe('the shipped-configuration section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		api.listShipped.mockResolvedValue({ items: [{ state: 'changed', targetObject: 'o-1', set: 's-1', latestVersion: '2.0', updateAvailable: true }] })
	})

	it('lists the shipped rows for the default schema on mount', async () => {
		const wrapper = await mounted()

		expect(api.listShipped).toHaveBeenCalledWith('caseType')
		expect(wrapper.vm.rows).toHaveLength(1)
		expect(wrapper.vm.present).toBe(null)
		expect(wrapper.vm.loadError).toBe('')
		expect(wrapper.vm.schemaOption.label).toBe('Case types')
	})

	it('counts the register\'s objects when nothing is shipped, and writes the empty text', async () => {
		api.listShipped.mockResolvedValue({ items: [] })
		api.countObjects.mockResolvedValue(4)
		const wrapper = await mounted()

		expect(wrapper.vm.present).toBe(4)
		expect(wrapper.vm.emptyText).toBe('The register holds 4 of these. None came from a starter set, so there is no shipped version to compare with.')
		api.countObjects.mockResolvedValue(0)
		wrapper.vm.present = null
		expect(wrapper.vm.emptyText).toBe('Nothing has been seeded yet.')
	})

	it('reloads only when the picked schema changes', async () => {
		const wrapper = await mounted()
		api.listShipped.mockClear()

		wrapper.vm.pick({ slug: 'caseType' })
		expect(api.listShipped).not.toHaveBeenCalled()
		wrapper.vm.pick(null)
		expect(api.listShipped).not.toHaveBeenCalled()
		wrapper.vm.pick({ slug: 'roleType' })
		expect(wrapper.vm.schema).toBe('roleType')
		expect(api.listShipped).toHaveBeenCalledWith('roleType')
	})

	it('shows a read error and clears the rows', async () => {
		api.listShipped.mockRejectedValue(new Error('down'))
		const wrapper = await mounted()

		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.vm.present).toBe(null)
		expect(wrapper.vm.loadError).toBe('Could not read the shipped configuration')
		expect(dialogs.showError).toHaveBeenCalledWith('Could not read the shipped configuration')
	})

	it('maps a row to its tone and adopt label', async () => {
		const wrapper = await mounted()
		expect(wrapper.vm.stateTone({ state: 'shipped' })).toBe('success')
		expect(wrapper.vm.stateTone({ state: 'removed' })).toBe('warning')
		expect(wrapper.vm.stateTone({ state: 'other' })).toBe('default')
		expect(wrapper.vm.label({ state: 'changed' })).toBe('label:changed')
		expect(wrapper.vm.updatable({ updateAvailable: true })).toBe(true)
		expect(wrapper.vm.adoptLabel({ state: 'changed', latestVersion: '2.0' })).toBe('Take 2.0 and lose your change')
		expect(wrapper.vm.adoptLabel({ state: 'shipped', latestVersion: '2.0' })).toBe('Take 2.0')
	})

	it('adopts a newer version, flagging the local-change loss, then reloads', async () => {
		api.adoptShipped.mockResolvedValue({})
		const wrapper = await mounted()
		api.listShipped.mockClear()

		await wrapper.vm.adopt({ state: 'changed', targetObject: 'o-1', set: 's-1' })

		expect(api.adoptShipped).toHaveBeenCalledWith('caseType', 'o-1', { set: 's-1', acceptLocalChangeLoss: true })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The newer version was taken')
		expect(api.listShipped).toHaveBeenCalledTimes(1)
	})

	it('shows the server reason when an adoption fails', async () => {
		const wrapper = await mounted()
		api.adoptShipped.mockRejectedValueOnce(Object.assign(new Error('x'), { response: { data: { reason: 'Already current' } } }))
		await wrapper.vm.adopt({ state: 'shipped', targetObject: 'o-1', set: 's-1' })
		expect(dialogs.showError).toHaveBeenLastCalledWith('Already current')

		api.adoptShipped.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.adopt({ state: 'shipped', targetObject: 'o-1', set: 's-1' })
		expect(dialogs.showError).toHaveBeenLastCalledWith('The newer version was not taken')
	})
})
