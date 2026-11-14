// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vervolgzaak plannen against board DqZaakDialogen: case type, title and date,
 * then repeat and end side by side, one short note instead of two paragraphs,
 * and "Plannen". Every field stays.
 *
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { results: [] } })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {} }))
function field (name) {
  return defineComponent({ name, props: ['modelValue', 'label', 'inputLabel', 'options', 'type', 'min'], render() {
	return h('div', { class: name, 'data-label': this.inputLabel || this.label }, this.$slots.default?.())
} })
}
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: field('NcTextField') }))
vi.mock('@nextcloud/vue/components/NcDateTimePicker', () => ({ default: field('NcDateTimePicker') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: field('NcSelect') }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: defineComponent({ name: 'NcButton', render() {
	return h('button', [this.$slots.icon?.(), this.$slots.default?.()])
} }) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: defineComponent({ name: 'CnDialog', render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} }) }))

const { default: Dialog } = await import('../../src/dialogs/CasePlanFollowUpDialog.vue')
const dutch = (key) => nl.translations[key] || key

/**
 * Mount the dialog on a case.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id: 'c-1' } } } } })
	await flushPromises()
	return w
}

describe('Vervolgzaak plannen against DqZaakDialogen', () => {
	it('asks case type, title, date, then repeat and end side by side', async () => {
		const w = await open()
		const labels = w.findAll('[data-label]').map((f) => dutch(f.attributes('data-label')))
		expect(labels.slice(0, 3)).toEqual(['Zaaktype', 'Titel van het vervolg', 'Datum'])
		const row = w.find('.case-plan__row')
		expect(row.findAll('[data-label]').map((f) => dutch(f.attributes('data-label')))).toEqual(['Herhalen (niet verplicht)', 'Eindigt (niet verplicht)'])
	})

	it('says it in one short note and plans with Plannen', async () => {
		const w = await open()
		expect(w.findAll('.case-plan__explainer')).toHaveLength(0)
		expect(dutch(w.find('.case-plan__note').text())).toBe('Tot de datum staat het vervolg als gepland onder Gerelateerd.')
		expect(dutch(w.find('[data-testid="case-plan-confirm"]').text())).toBe('Plannen')
	})
})
