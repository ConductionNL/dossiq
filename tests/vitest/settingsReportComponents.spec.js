// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's read-only settings cards: the facts list, the dependency
 * apps, the mandate matrix table and the advice card.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mockGet = vi.fn()
const mockState = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...a) => mockState(...a),
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => h('span', { class: `stub-${name}` }) })
	return {
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			setup: (props, { slots, emit }) => () => h('button', { onClick: () => emit('click') }, slots.default?.()),
		},
		NcEmptyContent: {
			name: 'NcEmptyContent',
			props: ['name', 'description'],
			setup: (props) => () => h('div', { class: 'stub-empty' }, `${props.name}: ${props.description}`),
		},
		NcLoadingIcon: stub('NcLoadingIcon'),
	}
})
vi.mock('vue-material-design-icons/FileDocumentMultiple.vue', () => ({
	default: { name: 'FileDocumentMultiple', render: () => null },
}))

const { default: SettingsFactsList } = await import('../../src/views/settings/components/SettingsFactsList.vue')
const { default: DependencyAppsList } = await import('../../src/views/settings/components/DependencyAppsList.vue')
const { default: DelegationMatrixTable } = await import('../../src/views/settings/components/DelegationMatrixTable.vue')
const { default: AdviceSettingsFacts } = await import('../../src/views/settings/components/AdviceSettingsFacts.vue')

beforeEach(() => {
	mockGet.mockReset()
	mockState.mockReset()
})

describe('SettingsFactsList', () => {
	it('puts each label beside its value inline, with code, hint and emphasis', () => {
		const wrapper = mount(SettingsFactsList, {
			props: {
				rows: [
					{ label: 'Command', value: 'occ dossiq:index', code: true },
					{ label: 'Status', value: 'Not set', muted: true, hint: 'Set it on System' },
					{ label: 'Total', value: '12', strong: true },
				],
			},
		})

		expect(wrapper.find('dl').classes()).toContain('settings-facts--inline')
		expect(wrapper.findAll('dt').map((dt) => dt.text())).toEqual(['Command', 'Status', 'Total'])
		const values = wrapper.findAll('dd')
		expect(values[0].find('code').text()).toBe('occ dossiq:index')
		expect(values[1].classes()).toContain('settings-facts__value--muted')
		expect(values[1].find('.settings-facts__hint').text()).toBe('Set it on System')
		expect(values[2].classes()).toContain('settings-facts__value--strong')
		expect(wrapper.find('.settings-facts__box').exists()).toBe(false)
	})

	it('draws every value in a box under the boxes layout, even a code row', () => {
		const wrapper = mount(SettingsFactsList, {
			props: { layout: 'boxes', rows: [{ label: 'Rows', value: '40', code: true }] },
		})

		expect(wrapper.find('dl').classes()).toContain('settings-facts--boxes')
		expect(wrapper.find('.settings-facts__box').text()).toBe('40')
		expect(wrapper.find('code').exists()).toBe(false)
	})

	it('accepts only the two layouts', () => {
		const { validator } = SettingsFactsList.props.layout
		expect(validator('inline')).toBe(true)
		expect(validator('boxes')).toBe(true)
		expect(validator('grid')).toBe(false)
	})
})

describe('DependencyAppsList', () => {
	it('lists each app by name and id, present or not installed', () => {
		mockState.mockReturnValue([
			{ name: 'OpenRegister', appId: 'openregister', present: true },
			{ name: 'Decidiq', appId: 'decidiq', present: false },
		])

		const wrapper = mount(DependencyAppsList)

		expect(mockState).toHaveBeenCalledWith('dossiq', 'dependencyApps', [])
		const rows = wrapper.findAll('li')
		expect(rows).toHaveLength(2)
		expect(rows[0].find('strong').text()).toBe('OpenRegister')
		expect(rows[0].find('.dependency-apps__id').text()).toBe('looked up as openregister')
		expect(rows[0].find('.cn-status-badge').text()).toBe('Present')
		expect(rows[0].find('.cn-status-badge').classes()).toContain('cn-status-badge--success')
		expect(rows[1].find('.cn-status-badge').text()).toBe('Not installed')
		expect(rows[1].find('.cn-status-badge').classes()).toContain('cn-status-badge--default')
	})
})

describe('DelegationMatrixTable', () => {
	it('says there are no mandate decisions when the list is empty', () => {
		const wrapper = mount(DelegationMatrixTable, { props: { matrices: [] } })

		expect(wrapper.find('.stub-empty').text()).toBe('No mandate decisions: No MandateringsBesluit entries yet. Create one or import an export.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows only the loading icon while loading', () => {
		const wrapper = mount(DelegationMatrixTable, { props: { matrices: [{ id: 'm-1' }], loading: true } })

		expect(wrapper.find('.stub-NcLoadingIcon').exists()).toBe(true)
		expect(wrapper.find('.stub-empty').exists()).toBe(false)
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('names each row by the first name it has, with status label and badge', () => {
		const wrapper = mount(DelegationMatrixTable, {
			props: {
				matrices: [
					{ id: 'm-1', name: 'Mandaatbesluit 2026', status: 'Active', inWerkingtreding: '2026-01-01', vervaldatum: '2027-01-01' },
					{ id: 'm-2', decisionName: 'Besluit B', status: 'expired' },
					{ id: 'm-3', mandateNumber: 'MB-3', status: 'lapsed' },
					{ id: 'm-4', status: 'draft' },
					{ id: 'm-5', status: 'onbekend' },
					{ id: 'm-6' },
				],
			},
		})

		const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((td) => td.text()))
		expect(rows.map((cells) => cells.slice(0, 5))).toEqual([
			['1', 'Mandaatbesluit 2026', 'Active', '2026-01-01', '2027-01-01'],
			['2', 'Besluit B', 'Expired', '—', '—'],
			['3', 'MB-3', 'Lapsed', '—', '—'],
			['4', 'm-4', 'Draft', '—', '—'],
			['5', 'm-5', 'onbekend', '—', '—'],
			['6', 'm-6', '—', '—', '—'],
		])
		const badges = wrapper.findAll('tbody .cn-status-badge').map((badge) => badge.classes().find((c) => c.startsWith('cn-status-badge--')))
		expect(badges).toEqual([
			'cn-status-badge--success',
			'cn-status-badge--error',
			'cn-status-badge--error',
			'cn-status-badge--default',
			'cn-status-badge--default',
			'cn-status-badge--default',
		])
	})

	it('emits edit with nothing for a new mandate, with the row for Edit, and import', async () => {
		const matrix = { id: 'm-1', name: 'A', status: 'draft' }
		const wrapper = mount(DelegationMatrixTable, { props: { matrices: [matrix] } })
		const buttons = wrapper.findAll('button')

		await buttons[0].trigger('click')
		await buttons[1].trigger('click')
		await buttons[2].trigger('click')

		expect(wrapper.emitted('edit')).toEqual([[null], [matrix]])
		expect(wrapper.emitted('import')).toEqual([[]])
	})
})

describe('AdviceSettingsFacts', () => {
	it('reads the active advisory bodies and shows the term, the advisers and the reminder', async () => {
		mockState.mockReturnValue({ defaultDeadlineDays: 14, warningOffsetDays: 3 })
		mockGet.mockResolvedValue({
			data: { results: [{ name: 'Brandweer', type: 'external', email: 'b@x.nl' }, { name: 'Juridisch', type: 'internal' }] },
		})

		const wrapper = mount(AdviceSettingsFacts)
		expect(wrapper.find('.stub-NcLoadingIcon').exists()).toBe(true)
		await flushPromises()

		expect(mockState).toHaveBeenCalledWith('dossiq', 'consultationSettings', {})
		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/advisoryBody',
			{ params: { active: true, _limit: 50 } },
		)
		expect(wrapper.find('.stub-NcLoadingIcon').exists()).toBe(false)
		expect(wrapper.findAll('dd').map((dd) => dd.text())).toEqual([
			'2 weeks after the request, adjustable per adviser',
			'Brandweer (external, by email), Juridisch (internal)',
			'3 days',
		])
	})

	it('shows no advisers when OpenRegister does not answer', async () => {
		mockState.mockReturnValue({})
		mockGet.mockRejectedValue(new Error('offline'))

		const wrapper = mount(AdviceSettingsFacts)
		await flushPromises()

		const values = wrapper.findAll('dd')
		expect(values[1].text()).toBe('None yet')
		expect(values[1].classes()).toContain('settings-facts__value--muted')
		expect(values[0].text()).toBe('4 weeks after the request, adjustable per adviser')
	})

	it('treats an answer without a results list as no advisers', async () => {
		mockState.mockReturnValue({})
		mockGet.mockResolvedValue({ data: { results: 'none' } })

		const wrapper = mount(AdviceSettingsFacts)
		await flushPromises()

		expect(wrapper.findAll('dd')[1].text()).toBe('None yet')
	})
})
