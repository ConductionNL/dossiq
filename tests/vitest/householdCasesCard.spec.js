// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqSociaalDomein's "Andere zaken van dit huishouden": the household's cases
 * in this domain the reader may open, and another domain's cases only after
 * "Opzoeken in andere domeinen" on a chosen ground, with nothing but whether
 * one exists and who to call.
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const emitted = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args), get: vi.fn(async () => ({ data: { grounds: [{ id: 'toestemming', label: 'Consent' }] } })) } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => url.replace('{caseId}', params.caseId) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue/components/NcButton', async () => ({ default: (await import('vue')).defineComponent({ name: 'NcButton', render() { return (this.$slots.default?.() ?? [])[0] ?? null } }) }))
vi.mock('@nextcloud/vue/components/NcSelect', async () => ({ default: (await import('vue')).defineComponent({ name: 'NcSelect', render: () => null }) }))
vi.mock('@nextcloud/vue/components/NcTextField', async () => {
	const { defineComponent, h } = await import('vue')
	return { default: defineComponent({ name: 'NcTextField', render: () => h('input', { class: 'bsn' }) }) }
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { CnDialog: defineComponent({ name: 'CnDialog', render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } }) }
})

const CrossDomainLookupDialog = (await import('../../src/dialogs/CrossDomainLookupDialog.vue')).default

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const widgets = simple.configAppend.widgets
const card = widgets.find((widget) => widget.id === 'case-household-cases-card')
const nl = read('l10n/nl.json').translations


describe('the household card (DqSociaalDomein)', () => {
	it('stands on the Overview after the report, where the report stands', () => {
		const overview = simple.configPatch.widgets['case-panels'].content.tabs.find((tab) => tab.label === 'Overview').widgetIds
		expect(overview.indexOf('case-household-cases-card')).toBe(overview.indexOf('case-summary') + 1)
		expect(card.visibleWhen).toEqual(widgets.find((widget) => widget.id === 'case-summary').visibleWhen)
		expect(nl[card.title]).toBe('Andere zaken van dit huishouden')
	})

	it('reads the household endpoint and links each case it may open', () => {
		expect(card.content.endpointSource.url).toBe('/apps/dossiq/api/cases/@objectId/household-cases')
		expect(card.content).toMatchObject({ rowRoute: 'CaseDetail', rowIdField: 'caseId' })
	})

	it('says what the reader sees of each row, and offers the lookup on a ground', () => {
		// The board's table: a case the reader may open, or only that one exists.
		expect(card.content.presentation).toBe('table')
		expect(card.content.columns.map((column) => nl[column.label])).toEqual(['Zaak', 'Zaaktype', 'Status', 'Zichtbaar voor u'])
		expect(card.content.columns[0].value).toEqual(['{identifier} {title}', '{domain}'])
		const visible = card.content.columns[3]
		expect(visible.value).toEqual(['{access}', 'Only that it exists'])
		expect(nl[card.content.valueLabels.access.full]).toBe('Ja')
		expect(nl[visible.value[1]]).toBe('Alleen dat hij bestaat')
		expect(card.content.footerAction).toMatchObject({ type: 'open-modal', target: 'CrossDomainLookupDialog', props: { household: true } })
		expect(nl[card.content.footerAction.label]).toBe('Opzoeken in andere domeinen')
	})
})

describe('the lookup dialog for a household', () => {
	beforeEach(() => {
		window.history.pushState({}, '', '/index.php/apps/dossiq/cases/case-1')
		post.mockReset()
		emitted.mockReset()
	})

	it('asks no BSN, refuses without a ground, and looks the household up on the one chosen', async () => {
		const wrapper = mount(CrossDomainLookupDialog, { props: { household: true }, global: { mocks: { t: (_app, text) => text } } })
		await flushPromises()
		expect(wrapper.find('.bsn').exists()).toBe(false)
		expect(wrapper.vm.canLookUp).toBe(false)

		post.mockResolvedValue({ data: { results: [{ exists: true, domain: 'Wmo', contact: 'anouk' }] } })
		wrapper.vm.ground = 'toestemming'
		await wrapper.vm.lookUp()
		expect(post).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/household-cases/lookup', { ground: 'toestemming' })
		expect(wrapper.vm.found).toEqual([{ exists: true, domain: 'Wmo', contact: 'anouk' }])
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
	})

	it('keeps the single-person lookup as it was', async () => {
		const wrapper = mount(CrossDomainLookupDialog, { global: { mocks: { t: (_app, text) => text } } })
		await flushPromises()
		expect(wrapper.find('.bsn').exists()).toBe(true)
	})
})
