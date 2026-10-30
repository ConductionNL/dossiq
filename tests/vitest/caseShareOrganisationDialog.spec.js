// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Sharing a case with, or handing it to, another organisation (board
 * DqZaakSamenwerken): one dialog, two acts, and the field selection kept as
 * an option of the share.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import CaseShareOrganisationDialog from '../../src/dialogs/CaseShareOrganisationDialog.vue'
import { FEDERATION_ALLOWED_FIELDS } from '../../src/utils/federatedShareHelpers.js'

const ROOT = path.resolve(__dirname, '../..')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

/**
 * Mount the dialog with one partner organisation.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const axios = (await import('@nextcloud/axios')).default
	// The organisations index lists only the user's own memberships; every
	// organisation, partners included, comes from the search endpoint.
	axios.get.mockImplementation((url) => Promise.resolve({
		data: String(url).includes('organisations/search')
			? { organisations: [{ id: 'org-1', name: 'Omgevingsdienst', domain: 'odzhz.nl', type: 'partner' }, { id: 'own', name: 'Gemeente', type: 'tenant' }] }
			: String(url).includes('organisations')
				? { results: [{ id: 'own', name: 'Gemeente', type: 'tenant' }] }
				: { title: 'Verlichting fietspad Lindelaan', identifier: '2026-0082' },
	}))
	axios.post.mockResolvedValue({ data: { success: true } })
	const wrapper = mount(CaseShareOrganisationDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				CnDialog: { props: ['eyebrow'], template: '<div><p class="eyebrow">{{ eyebrow }}</p><slot /><slot name="actions" /></div>' },
				CnChoiceCards: { name: 'CnChoiceCards', props: ['options', 'modelValue', 'layout', 'columns', 'label', 'hideLabel'], template: '<div class="choice-cards" />' },
				NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
				NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcSelect: { name: 'NcSelect', props: ['clearable', 'inputLabel'], template: '<div />' },
				NcTextField: { template: '<input>' },
			},
			mocks: { $route: { params: {} } },
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	return wrapper
}

describe('CaseShareOrganisationDialog', () => {
	// @spec openspec/changes/share-dialog-lists-every-partner/specs/share-dialog-lists-every-partner/spec.md#requirement-the-share-dialog-offers-every-partner-organisation
	it('offers partner organisations only, named with their domain, and heads with the case', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.vm.partners.map((p) => p.label)).toEqual(['Omgevingsdienst (odzhz.nl)'])
		expect(wrapper.find('.eyebrow').text()).toBe('Verlichting fietspad Lindelaan, 2026-0082')
	})

	it('shares through a partner share with permission, message and the fields left out', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({
			organisation: wrapper.vm.partners[0],
			message: ' Graag jullie advies. ',
			limitFields: true,
			sharedFields: ['title', 'status'],
		})
		await wrapper.vm.submit()
		const axios = (await import('@nextcloud/axios')).default
		const [url, body] = axios.post.mock.calls.at(-1)
		expect(url).toBe('/apps/dossiq/api/shares')
		expect(body).toEqual({
			caseId: 'case-1',
			shareType: 'partner',
			partnerId: 'org-1',
			permissionLevel: 'bekijken_bijdragen',
			message: 'Graag jullie advies.',
			fieldExclusions: FEDERATION_ALLOWED_FIELDS.filter((field) => !['title', 'status'].includes(field)),
		})
	})

	it('excludes nothing when fields are not limited', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({ organisation: wrapper.vm.partners[0] })
		expect(wrapper.vm.request().body.fieldExclusions).toEqual([])
	})

	it('hands over through a case transfer with the message as the reason', async () => {
		const wrapper = await mountDialog()
		await wrapper.setData({ mode: 'handover', organisation: wrapper.vm.partners[0], message: 'Hoort bij jullie' })
		const { url, body } = wrapper.vm.request()
		expect(url).toBe('/apps/dossiq/api/transfers')
		expect(body).toMatchObject({ caseId: 'case-1', targetOrganization: 'org-1', reason: 'Hoort bij jullie' })
	})

	it('cannot share without an organisation, or with every field taken away', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.vm.canSubmit).toBe(false)
		await wrapper.setData({ organisation: wrapper.vm.partners[0], limitFields: true, sharedFields: [] })
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	// @spec openspec/specs/share-org-not-clearable/spec.md#requirement-the-organisation-choice-has-no-clear-button
	it('offers no clear button on the organisation, as on the act and the permission', async () => {
		const wrapper = await mountDialog()
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects.length).toBeGreaterThan(0)
		expect(selects.every((select) => select.props('clearable') === false)).toBe(true)
	})

	it('opens from Meer on the simple case page', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(registry).toContain('CaseShareOrganisationDialog: {')
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
		const overlay = simple.pages.find((page) => page.id === 'CaseDetail')
		const action = overlay.configAppend.headerActions.find((entry) => entry.id === 'share-with-organisation')
		expect(action).toMatchObject({ type: 'open-modal', target: 'CaseShareOrganisationDialog', group: 'Case' })
	})
})

describe('CaseShareOrganisationDialog mode picker', () => {
	it('offers Share and Hand over as the library choice cards, each with its own line', async () => {
		const wrapper = await mountDialog()
		const cards = wrapper.findComponent({ name: 'CnChoiceCards' })
		expect(cards.exists()).toBe(true)
		expect(cards.props('options').map((option) => [option.value, option.label, option.description])).toEqual([
			['share', 'Share', 'You stay the owner; they work along'],
			['handover', 'Hand over', 'They become the owner; you can still read along'],
		])
		expect(cards.props('modelValue')).toBe('share')
		expect(wrapper.find('.case-share-org__modes').exists()).toBe(false)
	})

	it('sets the two acts as option rows side by side under the title, the legend for assistive tech only', async () => {
		const cards = (await mountDialog()).findComponent({ name: 'CnChoiceCards' })
		expect(cards.props('layout')).toBe('rows')
		expect(cards.props('columns')).toBe(2)
		expect(cards.props('label')).toBe('What you do')
		expect(cards.props('hideLabel')).toBe(true)
	})

	it('switches the act from the cards', async () => {
		const wrapper = await mountDialog()
		wrapper.findComponent({ name: 'CnChoiceCards' }).vm.$emit('update:modelValue', 'handover')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.mode).toBe('handover')
	})
})

describe('the message field label (DqZaakSamenwerken)', () => {
	it('names the field and writes "(optional)" beside it in the muted ink', async () => {
		const wrapper = await mountDialog()
		const label = wrapper.find('label[for="case-share-message"]')
		expect(label.exists()).toBe(true)
		expect(label.text()).toContain('Message')
		expect(label.find('.case-share-org__optional').text()).toBe('(optional)')
	})
})

describe('CaseShareOrganisationDialog spacing', () => {
	it('puts the first field 16px under the choice cards, as between the fields', () => {
		const sfc = fs.readFileSync(path.join(ROOT, 'src/dialogs/CaseShareOrganisationDialog.vue'), 'utf8')
		const at = sfc.indexOf('\n.case-share-org > .cn-choice-cards {')
		expect(at).toBeGreaterThan(-1)
		expect(sfc.slice(at, sfc.indexOf('}', at))).toContain('margin-bottom: 4px')
	})
})

describe('the message field on DqZaakSamenwerken (r34: Bericht 9.5px low)', () => {
	const sfc = fs.readFileSync(path.join(ROOT, 'src/dialogs/CaseShareOrganisationDialog.vue'), 'utf8')
	const rule = (selector) => {
		const at = sfc.indexOf('\n' + selector + ' {')
		expect(at, selector).toBeGreaterThan(-1)
		return sfc.slice(at, sfc.indexOf('}', at))
	}

	it('sets the label at 14px on the normal line, as the select labels above it', () => {
		const label = rule('.case-share-org__label')
		expect(label).toContain('font-size: 14px;')
		expect(label).toContain('line-height: normal;')
	})

	it('stands the control 6px under the label, counting the field\'s 2px focus inset', () => {
		expect(rule('.case-share-org__field')).toContain('gap: 4px;')
	})
})

describe('the selects on DqZaakSamenwerken (r35: fields 18px apart, the card 5.7px low)', () => {
	const sfc = fs.readFileSync(path.join(ROOT, 'src/dialogs/CaseShareOrganisationDialog.vue'), 'utf8')

	it('ends each select 2px under its wrapper, so its 40px control stands 16px above the next label', () => {
		const at = sfc.indexOf('\n.case-share-org > .v-select.select {')
		expect(at).toBeGreaterThan(-1)
		expect(sfc.slice(at, sfc.indexOf('}', at))).toContain('margin-bottom: 2px;')
	})
})
