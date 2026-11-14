// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A case that came in through the DSO can be forwarded to another competent
 * authority or shared in a collaboration from the case page, through the
 * case's DSO routes.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DsoCollaborationDialog from '../../src/dialogs/DsoCollaborationDialog.vue'
import DsoForwardDialog from '../../src/dialogs/DsoForwardDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const CASE_ID = '11111111-1111-4111-8111-111111111111'

const field = {
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
}
const stubs = {
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: field,
	NcTextArea: field,
}

/**
 * The axios double.
 *
 * @return {Promise<object>} The mocked axios.
 */
async function axiosDouble() {
	return (await import('@nextcloud/axios')).default
}

const cases = [
	{
		name: 'forward',
		component: DsoForwardDialog,
		prefix: 'dso-forward',
		second: 'reason',
		route: 'doorsturen',
		body: { targetBevoegdGezag: 'Provincie Utrecht', reason: 'Provinciale bevoegdheid' },
		action: 'dso-forward',
		target: 'DsoForwardDialog',
		dutch: 'Doorsturen naar ander bevoegd gezag',
	},
	{
		name: 'collaborate',
		component: DsoCollaborationDialog,
		prefix: 'dso-collaborate',
		second: 'rationale',
		route: 'samenwerking',
		body: { requestedCompetentAuthority: 'Provincie Utrecht', rationale: 'Provinciale bevoegdheid' },
		action: 'dso-collaborate',
		target: 'DsoCollaborationDialog',
		dutch: 'Samenwerking starten',
	},
]

describe.each(cases)('the $name action on a DSO case', (spec) => {
	beforeEach(async () => {
		const axios = await axiosDouble()
		axios.post.mockReset()
	})

	it('is a header action shown only on a case that came in through the DSO', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const action = caseDetail.config.headerActions.find((entry) => entry.id === spec.action)
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe(spec.target)
		expect(action.props.caseId).toBe('@objectId')
		expect(action.visibleWhen).toEqual({
			all: [
				{ field: 'dsoStatus', op: 'notEmpty' },
				{ field: '@self.archived', op: 'empty' },
			],
		})
		expect(registrySource).toContain(`${spec.target}: {`)
		expect(nl[action.label]).toBe(spec.dutch)
	})

	it('posts the authority and the reason to the case\'s DSO route and says it went', async () => {
		const axios = await axiosDouble()
		axios.post.mockResolvedValue({ data: { status: 'ok' } })
		const wrapper = mount(spec.component, { props: { caseId: CASE_ID }, global: { stubs } })

		const confirm = wrapper.find(`[data-testid="${spec.prefix}-confirm"]`)
		expect(confirm.attributes('disabled')).toBeDefined()
		await wrapper.find(`[data-testid="${spec.prefix}-authority"]`).setValue(' Provincie Utrecht ')
		await wrapper.find(`[data-testid="${spec.prefix}-${spec.second}"]`).setValue('Provinciale bevoegdheid')
		expect(confirm.attributes('disabled')).toBeUndefined()
		await confirm.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(`/index.php/apps/dossiq/api/dso/cases/${CASE_ID}/${spec.route}`, spec.body)
		expect(wrapper.find(`[data-testid="${spec.prefix}-sent"]`).text()).toContain('Provincie Utrecht')
		expect(wrapper.emitted('done')).toEqual([[{ authority: 'Provincie Utrecht' }]])
	})

	it('keeps the dialog open with the refusal when the case may not be changed', async () => {
		const axios = await axiosDouble()
		axios.post.mockRejectedValue({ response: { status: 403 } })
		const wrapper = mount(spec.component, { props: { caseId: CASE_ID }, global: { stubs } })
		wrapper.vm.authority = 'Provincie Utrecht'
		wrapper.vm[spec.second] = 'x'
		await wrapper.vm.send()

		expect(wrapper.find(`[data-testid="${spec.prefix}-error"]`).text()).toBe('You may not change this case.')
		expect(wrapper.find(`[data-testid="${spec.prefix}-sent"]`).exists()).toBe(false)
		expect(wrapper.emitted('done')).toBeUndefined()
	})

	it('reads the case from the route when the action hands the unresolved token, and sends nothing without one', async () => {
		const axios = await axiosDouble()
		axios.post.mockRejectedValue({ response: { status: 500 } })
		const routed = mount(spec.component, {
			props: { caseId: '@objectId' },
			global: { stubs, mocks: { $route: { params: { id: 'case-9' } } } },
		})
		expect(routed.vm.targetCaseId).toBe('case-9')
		routed.vm.authority = 'Gemeente'
		routed.vm[spec.second] = 'x'
		await routed.vm.send()
		expect(axios.post.mock.calls[0][0]).toBe(`/index.php/apps/dossiq/api/dso/cases/case-9/${spec.route}`)
		expect(routed.vm.error).toBe('The DSO could not be reached. Nothing was sent; try again later.')

		axios.post.mockClear()
		const orphan = mount(spec.component, { props: { caseId: '' }, global: { stubs } })
		orphan.vm.authority = 'Gemeente'
		orphan.vm[spec.second] = 'x'
		await orphan.vm.send()
		expect(axios.post).not.toHaveBeenCalled()
	})
})
