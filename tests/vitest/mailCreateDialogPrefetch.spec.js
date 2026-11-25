// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * New case from a mail: the form's schema is read into the store side by
 * side with the case type's questions, under the slug the library's action
 * host resolves, so the dialog no longer waits for one read after the other.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { formTypeSlug, prefetchFormSchema } from '../../src/mailCreateDialog/prefetchFormSchema.js'

const mockStore = {
	objectTypeRegistry: {},
	registerObjectType: vi.fn(function(slug, schema, register) {
		this.objectTypeRegistry[slug] = { schema, register }
	}),
	fetchSchema: vi.fn(),
}
vi.mock('@conduction/nextcloud-vue', () => ({
	DYNAMIC_KEY_PREFIX: 'x-prop:',
	useObjectStore: () => mockStore,
}))
vi.mock('@conduction/nextcloud-vue/css/index.css', () => ({}))
vi.mock('../../src/registry.js', () => ({ default: {} }))
const mockHostProps = vi.fn()
vi.mock('../../src/mailCreateDialog/MailNewCaseHost.vue', () => ({
	default: defineComponent({
		name: 'MailNewCaseHost',
		props: { action: { type: Object, required: true } },
		setup(props) {
			mockHostProps(props.action)
			return () => h('div')
		},
	}),
}))
let releaseAnswers
const mockMailAnswersFor = vi.fn(() => new Promise((resolve) => {
	releaseAnswers = resolve
}))
vi.mock('../../src/mailCreateDialog/mailAnswers.js', () => ({
	mailAnswersFor: (...args) => mockMailAnswersFor(...args),
}))

afterEach(() => {
	mockStore.objectTypeRegistry = {}
	mockStore.registerObjectType.mockClear()
	mockStore.fetchSchema.mockReset()
	mockHostProps.mockReset()
	mockMailAnswersFor.mockClear()
	document.body.innerHTML = ''
})

describe('the form schema read before the dialog opens', () => {
	it('uses the slug already registered for the register and schema', () => {
		mockStore.objectTypeRegistry = { case: { register: 'dossiq', schema: 'case' } }
		expect(formTypeSlug(mockStore, 'dossiq', 'case')).toBe('case')
		expect(mockStore.registerObjectType).not.toHaveBeenCalled()
	})

	it('registers register/schema when nothing is registered, as the action host would', () => {
		mockStore.objectTypeRegistry = { other: { register: 'dossiq', schema: 'task' } }
		expect(formTypeSlug(mockStore, 'dossiq', 'case')).toBe('dossiq/case')
		expect(mockStore.registerObjectType).toHaveBeenCalledWith('dossiq/case', 'case', 'dossiq')
	})

	it('reads the schema through the store and never fails', async () => {
		mockStore.fetchSchema.mockResolvedValueOnce({ slug: 'case' })
		expect(await prefetchFormSchema(mockStore, { register: 'dossiq', schema: 'case' })).toEqual({ slug: 'case' })
		expect(mockStore.fetchSchema).toHaveBeenCalledWith('dossiq/case')
		mockStore.fetchSchema.mockRejectedValueOnce(new Error('offline'))
		expect(await prefetchFormSchema(mockStore, { register: 'dossiq', schema: 'case' })).toBeNull()
	})

	it('reads nothing for an action without a plain register and schema', async () => {
		expect(await prefetchFormSchema(mockStore, { schema: 'case' })).toBeNull()
		expect(await prefetchFormSchema(mockStore, { register: 'dossiq', schema: '#/components/schemas/case' })).toBeNull()
		expect(await prefetchFormSchema(mockStore, null)).toBeNull()
		expect(mockStore.fetchSchema).not.toHaveBeenCalled()
	})
})

describe('opening New case from a mail', () => {
	it('reads the schema while the questions are still being read, then opens with the mail answers', async () => {
		mockStore.fetchSchema.mockResolvedValue({ slug: 'case' })
		const { openNewCaseDialog } = await import('../../src/mailCreateDialog/openNewCaseDialog.js')
		const opened = openNewCaseDialog({ initialData: { caseType: 'woo-type', title: 'Street light' }, mail: { values: { senderName: 'Ahmed Bakker' } } })
		await flushPromises()

		expect(mockMailAnswersFor).toHaveBeenCalledWith('woo-type', { senderName: 'Ahmed Bakker' })
		expect(mockStore.fetchSchema).toHaveBeenCalledWith('dossiq/case')
		expect(mockHostProps).not.toHaveBeenCalled()

		releaseAnswers({ 'x-prop:q-name': 'Ahmed Bakker' })
		await flushPromises()
		expect(mockHostProps).toHaveBeenCalledTimes(1)
		expect(mockHostProps.mock.calls[0][0].props).toMatchObject({ caseType: 'woo-type', title: 'Street light', 'x-prop:q-name': 'Ahmed Bakker' })
		expect(opened).toBeInstanceOf(Promise)
	})
})
