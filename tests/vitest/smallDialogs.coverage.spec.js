// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The small confirm dialogs no other spec mounts end to end:
 * EndAssignmentDialog, DeleteRolDialog, CaseTypeNewVersionDialog,
 * CaseTypeDuplicateDialog and CaseCopyDialog. What is pinned: which event
 * each gesture emits and with what, when the confirm button is disabled,
 * that closing the dialog frame closes it (and opening it does not), the
 * exact URL each POST goes to, where a success lands, and the sentence a
 * refusal shows.
 *
 * The `@nextcloud/vue` and `@conduction/nextcloud-vue` frames are stubbed by
 * their registered names, so every button is a plain button that forwards
 * `disabled` and `click`, and every field forwards `update:modelValue`.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/specs/zaaktype-versioning/spec.md
 * @spec openspec/specs/workflow-import-export/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import CaseCopyDialog from '../../src/dialogs/CaseCopyDialog.vue'
import CaseTypeDuplicateDialog from '../../src/dialogs/CaseTypeDuplicateDialog.vue'
import CaseTypeNewVersionDialog from '../../src/dialogs/CaseTypeNewVersionDialog.vue'
import DeleteRolDialog from '../../src/dialogs/DeleteRolDialog.vue'
import EndAssignmentDialog from '../../src/dialogs/EndAssignmentDialog.vue'

vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

/**
 * A dialog frame that renders its slots and can be closed or opened from the test.
 */
const DialogStub = {
	name: 'DialogStub',
	inheritAttrs: false,
	props: { name: { type: String, default: '' }, open: { type: Boolean, default: true } },
	emits: ['update:open', 'closing'],
	render() {
		return h('div', { class: 'dialog-stub', 'data-open': String(this.open) }, [
			h('h2', { class: 'dialog-stub__name' }, this.name),
			h('button', { class: 'stub-close', onClick: () => { this.$emit('update:open', false); this.$emit('closing') } }),
			h('button', { class: 'stub-reopen', onClick: () => this.$emit('update:open', true) }),
			this.$slots.default?.(),
			this.$slots.actions?.(),
		])
	},
}

const ButtonStub = {
	name: 'ButtonStub',
	props: { disabled: { type: Boolean, default: false } },
	emits: ['click'],
	render() {
		return h('button', { class: 'button-stub', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
	},
}

const FieldStub = {
	name: 'FieldStub',
	props: { modelValue: { type: null, default: '' } },
	emits: ['update:modelValue'],
	render() {
		return h('input', {
			class: 'field-stub',
			value: this.modelValue,
			onInput: (e) => this.$emit('update:modelValue', e.target.value),
		})
	},
}

const CheckboxStub = {
	name: 'CheckboxStub',
	props: { modelValue: { type: Boolean, default: false } },
	emits: ['update:modelValue'],
	render() {
		return h('input', {
			type: 'checkbox',
			class: 'checkbox-stub',
			checked: this.modelValue,
			onChange: (e) => this.$emit('update:modelValue', e.target.checked),
		})
	},
}

const stubs = {
	NcDialog: DialogStub,
	CnDialog: DialogStub,
	NcButton: ButtonStub,
	NcTextField: FieldStub,
	NcCheckboxRadioSwitch: CheckboxStub,
}

/**
 * Mount a dialog with the stubs and an optional router.
 *
 * @param {object} component The dialog.
 * @param {object} props     Its props.
 * @param {object} extra     Extra globals ($router / $route mocks).
 * @return {object} The wrapper.
 */
function mountDialog(component, props = {}, extra = {}) {
	return mount(component, { props, global: { stubs, mocks: extra } })
}

/**
 * The action buttons, in render order.
 *
 * @param {object} wrapper The dialog wrapper.
 * @return {Array} The button wrappers.
 */
function buttons(wrapper) {
	return wrapper.findAll('.button-stub')
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.post.mockReset()
})

describe('EndAssignmentDialog', () => {
	it('proposes today and saves the date as typed', async () => {
		const wrapper = mountDialog(EndAssignmentDialog, { assignment: { id: 'a1' } })
		const input = wrapper.find('#ea-end')

		expect(input.element.value).toBe(new Date().toISOString().slice(0, 10))

		await input.setValue('2026-12-31')
		await buttons(wrapper)[1].trigger('click')

		expect(wrapper.emitted('save')).toEqual([['2026-12-31']])
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('refuses to save without a date', async () => {
		const wrapper = mountDialog(EndAssignmentDialog, { assignment: { id: 'a1' } })

		await wrapper.find('#ea-end').setValue('')

		expect(buttons(wrapper)[1].attributes('disabled')).toBeDefined()
	})

	it('closes from Cancel and from the frame, but not when the frame opens', async () => {
		const wrapper = mountDialog(EndAssignmentDialog, { assignment: { id: 'a1' } })

		await wrapper.find('.stub-reopen').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()

		await buttons(wrapper)[0].trigger('click')
		await wrapper.find('.stub-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})

describe('DeleteRolDialog', () => {
	it('names the role and confirms the delete', async () => {
		const wrapper = mountDialog(DeleteRolDialog, { role: { id: 'r1', name: 'Applicant' } })

		expect(wrapper.text()).toContain('Delete role Applicant?')
		expect(buttons(wrapper)[1].attributes('disabled')).toBeUndefined()

		await buttons(wrapper)[1].trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([[]])
	})

	it('falls back to the id when the role has no name', () => {
		const wrapper = mountDialog(DeleteRolDialog, { role: { id: 'r7' } })

		expect(wrapper.text()).toContain('Delete role r7?')
	})

	it('shows why a delete is blocked and disables it', () => {
		const wrapper = mountDialog(DeleteRolDialog, {
			role: { id: 'r1', name: 'Applicant' },
			blockedReason: 'Two cases still use this role.',
		})

		expect(wrapper.find('.rol-manager__warning').text()).toBe('Two cases still use this role.')
		expect(buttons(wrapper)[1].attributes('disabled')).toBeDefined()
	})

	it('closes from Cancel and from the frame, but not when the frame opens', async () => {
		const wrapper = mountDialog(DeleteRolDialog, { role: { id: 'r1' } })

		await wrapper.find('.stub-reopen').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()

		await buttons(wrapper)[0].trigger('click')
		await wrapper.find('.stub-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})

describe.each([
	['CaseTypeNewVersionDialog', CaseTypeNewVersionDialog, 'new-version', 'The draft was made but could not be opened.'],
	['CaseTypeDuplicateDialog', CaseTypeDuplicateDialog, 'copy', 'The copy was made but could not be opened.'],
])('%s', (name, component, action, lost) => {
	it('posts for the given case type and lands on the one it made', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'ct-2' } })
		const push = vi.fn()
		const wrapper = mountDialog(component, { caseTypeId: 'ct/1' }, { $router: { push }, $route: { params: { id: 'other' } } })

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith(`/apps/dossiq/api/case-definitions/ct%2F1/${action}`)
		expect(wrapper.emitted('close')).toEqual([[]])
		expect(push).toHaveBeenCalledWith({ name: 'CaseTypeDetail', params: { id: 'ct-2' } })
	})

	it('falls back to the route id', async () => {
		axiosMock.post.mockResolvedValue({ data: { '@self': { id: 'ct-9' } } })
		const push = vi.fn()
		const wrapper = mountDialog(component, {}, { $router: { push }, $route: { params: { id: 'ct-3' } } })

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith(`/apps/dossiq/api/case-definitions/ct-3/${action}`)
		expect(push).toHaveBeenCalledWith({ name: 'CaseTypeDetail', params: { id: 'ct-9' } })
	})

	it('says the result was lost when the answer carries no id', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const push = vi.fn()
		const wrapper = mountDialog(component, { caseTypeId: 'ct-1' }, { $router: { push } })

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(push).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain(lost)
	})

	it('shows the sentence for a refusal and stays open', async () => {
		axiosMock.post.mockRejectedValue({ response: { status: 403, data: { message: '/var/www secret' } } })
		const wrapper = mountDialog(component, { caseTypeId: 'ct-1' }, { $router: { push: vi.fn() } })

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Your account may not do this.')
		expect(wrapper.text()).not.toContain('/var/www')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(buttons(wrapper)[1].attributes('disabled')).toBeUndefined()
	})

	it('closes from Cancel and from the frame', async () => {
		const wrapper = mountDialog(component, { caseTypeId: 'ct-1' })

		await buttons(wrapper)[0].trigger('click')
		await wrapper.find('.stub-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})
})

describe('CaseCopyDialog', () => {
	it('proposes a title from the source case', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'Permit 12' } })
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c 1' })
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case/c%201')
		expect(wrapper.find('.field-stub').element.value).toBe('Copy of Permit 12')
	})

	it('proposes a plain title when the source cannot be read', async () => {
		axiosMock.get.mockRejectedValue(new Error('down'))
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' })
		await flushPromises()

		expect(wrapper.find('.field-stub').element.value).toBe('Copy')
	})

	it('reads nothing without a case', async () => {
		const wrapper = mountDialog(CaseCopyDialog, {}, { $route: { params: {} } })
		await flushPromises()

		expect(axiosMock.get).not.toHaveBeenCalled()
		expect(buttons(wrapper)[1].attributes('disabled')).toBeDefined()
	})

	it('posts the trimmed title and the documents choice, then opens the copy', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'Permit 12' } })
		axiosMock.post.mockResolvedValue({ data: { id: 'c2' } })
		const push = vi.fn()
		const wrapper = mountDialog(CaseCopyDialog, {}, { $router: { push }, $route: { params: { id: 'c1' } } })
		await flushPromises()

		await wrapper.find('.field-stub').setValue('  My copy  ')
		await wrapper.find('.checkbox-stub').setValue(true)
		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/dossiq/api/case/c1/copy', { title: 'My copy', documents: true })
		expect(wrapper.emitted('close')).toEqual([[]])
		expect(push).toHaveBeenCalledWith({ name: 'CaseDetail', params: { id: 'c2' } })
	})

	it('closes without navigating when the answer has no id', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'A' } })
		axiosMock.post.mockResolvedValue({ data: {} })
		const push = vi.fn()
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' }, { $router: { push } })
		await flushPromises()

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(wrapper.emitted('close')).toEqual([[]])
		expect(push).not.toHaveBeenCalled()
	})

	it('refuses a blank title before posting', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'A' } })
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' })
		await flushPromises()

		await wrapper.find('.field-stub').setValue('   ')
		await wrapper.vm.confirm()

		expect(buttons(wrapper)[1].attributes('disabled')).toBeDefined()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('shows the sentence for the refusal code and stays open', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'A' } })
		axiosMock.post.mockRejectedValue({ response: { data: { code: 'copy_failed' } } })
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' })
		await flushPromises()

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="case-copy-error"]').text()).toBe('The copy could not be created.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('falls back to a generic sentence for an unknown refusal', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'A' } })
		axiosMock.post.mockRejectedValue(new Error('network'))
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' })
		await flushPromises()

		await buttons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="case-copy-error"]').text()).toBe('This did not work. Try again.')
	})

	it('closes from Cancel and from the frame', async () => {
		axiosMock.get.mockResolvedValue({ data: { title: 'A' } })
		const wrapper = mountDialog(CaseCopyDialog, { caseId: 'c1' })
		await flushPromises()

		await buttons(wrapper)[0].trigger('click')
		await wrapper.find('.stub-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
