// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Giving a selection of cases to another handler: what each control of the
 * dialog changes, and what the dialog tells its parent.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/services/bulkJobApi.js', async (importOriginal) => {
	const real = await importOriginal()
	return { ACTION_REASSIGN: real.ACTION_REASSIGN, readRefusal: real.readRefusal, previewBulkJob: vi.fn() }
})

/**
 * A stub that renders its default and actions slots.
 *
 * @param {string} name The component name.
 * @return {object}
 */
function plain(name) {
	return defineComponent({
		name,
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: plain('NcButton'),
	NcDialog: plain('NcDialog'),
	NcNoteCard: plain('NcNoteCard'),
	NcTextArea: plain('NcTextArea'),
	NcTextField: plain('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnBulkJobProgress: plain('CnBulkJobProgress'), CnSelectionScope: plain('CnSelectionScope') }))

const { default: ReassignSelectionDialog } = await import('../../src/dialogs/ReassignSelectionDialog.vue')

/**
 * Mount the dialog over a selection.
 *
 * @return {object} The wrapper.
 */
function dialog() {
	return mount(ReassignSelectionDialog, {
		props: { open: true, selectedIds: [4, 'c-5'], matchingTotal: 40 },
	})
}

describe('ReassignSelectionDialog controls', () => {
	it('takes the scope the scope picker chooses', async () => {
		const w = dialog()
		await w.findComponent({ name: 'CnSelectionScope' }).vm.$emit('update:scope', 'all')
		expect(w.vm.scope).toBe('all')
	})

	it('takes the handler and the justification as they are typed', async () => {
		const w = dialog()
		await w.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'marieke')
		await w.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Vertrek collega')
		expect(w.vm.handler).toBe('marieke')
		expect(w.vm.justification).toBe('Vertrek collega')
		expect(w.vm.canRehearse).toBe(true)
	})

	it('passes the dialog closing on, and closes from Cancel', async () => {
		const w = dialog()
		await w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		await w.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		expect(w.emitted('update:open')).toEqual([[false], [false]])
	})

	it('shows the error it holds', async () => {
		const w = dialog()
		expect(w.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
		w.vm.error = 'Give a reason'
		await w.vm.$nextTick()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Give a reason')
	})
})

describe('ReassignSelectionDialog progress', () => {
	it('follows the job and reports the finished reassignment', async () => {
		const w = dialog()
		w.vm.job = { id: 'job-1', status: 'running' }
		await w.vm.$nextTick()
		expect(w.findComponent({ name: 'CnSelectionScope' }).exists()).toBe(false)
		expect(w.findAllComponents({ name: 'NcButton' })).toHaveLength(1)
		expect(w.findComponent({ name: 'NcButton' }).text()).toBe('Close')

		const progress = w.findComponent({ name: 'CnBulkJobProgress' })
		await progress.vm.$emit('update:job', { id: 'job-1', status: 'done' })
		expect(w.vm.job).toEqual({ id: 'job-1', status: 'done' })

		await progress.vm.$emit('finished', { done: 2 })
		expect(w.emitted('reassigned')).toEqual([[{ done: 2 }]])
	})
})
