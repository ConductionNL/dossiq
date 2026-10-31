// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Herinneren against board DqZaakDialogen: what to remind about first, then the
 * date, then the colleague, who is optional and means you when left empty.
 *
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'pieter' }) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {} }))
vi.mock('../../src/store/modules/engineTask.js', () => ({ useEngineTaskStore: () => ({ saveTask: vi.fn() }) }))
function field (name) {
  return defineComponent({ name, props: ['modelValue', 'label', 'type', 'min'], render() {
	return h('div', { class: name, 'data-label': this.label })
} })
}
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: field('NcTextField') }))
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({ default: field('NcDateTimePicker') }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: defineComponent({ name: 'NcButton', render() {
	return h('button', this.$slots.default?.())
} }) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: defineComponent({ name: 'CnDialog', render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} }) }))

const { default: RemindDialog } = await import('../../src/dialogs/RemindDialog.vue')

describe('Herinneren against DqZaakDialogen', () => {
	it('asks what, when and then the optional colleague, in the board\'s words', () => {
		const w = mount(RemindDialog, { global: { mocks: { $route: { params: { id: 'c-1' } } } } })
		const labels = w.findAll('[data-label]').map((f) => nl.translations[f.attributes('data-label')] || f.attributes('data-label'))
		expect(labels).toEqual(['Waaraan herinneren', 'Datum', 'Collega (niet verplicht)'])
		expect(w.find('.remind__explainer').exists()).toBe(false)
		expect(nl.translations['Set reminder']).toBe('Herinnering zetten')
	})

	it('reminds you when the colleague is left empty', () => {
		const w = mount(RemindDialog, { global: { mocks: { $route: { params: { id: 'c-1' } } } } })
		w.vm.assignee = '   '
		expect(w.vm.form.assignee).toBe('pieter')
	})
})
