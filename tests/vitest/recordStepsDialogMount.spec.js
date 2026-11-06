/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RecordStepsDialog against the wizard: it validates a step, writes the
 * record, adds the task and reports a refusal on the step.
 *
 * @vitest-environment jsdom
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const create = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match) }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => ({ create }) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsField: { name: 'CnSettingsField', props: ['field', 'modelValue', 'hint'], emits: ['update:modelValue'], render: () => null },
	CnWizardDialog: {
		name: 'CnWizardDialog',
		props: ['steps', 'dialogTitle', 'validate', 'submitLabel', 'successText'],
		emits: ['submit', 'close'],
		data: () => ({ result: null, failure: '' }),
		methods: {
			setResult(result) { this.result = result },
			setError(message) { this.failure = message },
		},
		render() {
			return this.$slots['step-a']?.()
		},
	},
}))

const { default: RecordStepsDialog } = await import('../../src/dialogs/RecordStepsDialog.vue')

const props = {
	dialogTitle: 'Start',
	schema: 'handhavingsactie',
	steps: [{ id: 'a', title: 'A', fields: [{ key: 'severity', label: 'Severity', required: true }, { key: 'days', label: 'Days', type: 'number', transient: true, default: 14 }] }],
	prefillFrom: { schema: 'lhsRecommendation', map: { severity: 'severity' } },
	task: { title: 'Assess', dueInDaysField: 'days' },
}

/** @return {Promise<object>} The mounted dialog with its prefill read. */
async function open() {
	const w = mount(RecordStepsDialog, { props, global: { mocks: { $route: { params: { id: 'c-1' } } } } })
	await flushPromises()
	return w
}

describe('RecordStepsDialog', () => {
	beforeEach(() => {
		http.get.mockReset().mockResolvedValue({ data: { results: [{ severity: 'ernstig' }] } })
		http.post.mockReset().mockResolvedValue({ data: { id: 'r-1' } })
		create.mockReset().mockResolvedValue({ id: 't-1' })
	})

	it('opens on the case record, keeping the defaults', async () => {
		const w = await open()
		expect(http.get.mock.calls[0][1].params.case).toBe('c-1')
		expect(w.vm.values).toEqual({ days: 14, severity: 'ernstig' })
	})

	it('holds a step with an empty required field', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const w = await open()
		expect(w.vm.validateStep('a')).toBe('Severity is required.')
		w.vm.values.severity = 'gering'
		expect(w.vm.validateStep('a')).toBe(true)
	})

	it('writes the record without the transient field, then the task', async () => {
		const w = await open()
		await w.vm.save()
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/handhavingsactie', { case: 'c-1', severity: 'ernstig' })
		expect(create.mock.calls[0][0]).toMatchObject({ case: 'c-1', title: 'Assess' })
		expect(w.findComponent({ name: 'CnWizardDialog' }).vm.result).toEqual({ success: true })
	})

	it('keeps a refused write on the step, with the server sentence and no task', async () => {
		http.post.mockRejectedValue({ response: { data: { message: 'Not allowed' } } })
		const w = await open()
		await w.vm.save()
		expect(w.findComponent({ name: 'CnWizardDialog' }).vm.failure).toBe('Not allowed')
		expect(create).not.toHaveBeenCalled()
	})

	it('says so when the record is saved and the task is not', async () => {
		create.mockResolvedValue(null)
		const w = await open()
		await w.vm.save()
		expect(w.findComponent({ name: 'CnWizardDialog' }).vm.result.success).toBe(false)
	})
})
