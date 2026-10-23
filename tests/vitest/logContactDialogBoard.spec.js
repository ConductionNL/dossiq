// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contact vastleggen in the words of board DqZaakDialogen: "Starttijd" and
 * "Wie belde" marked optional, and "Vastleggen" with a phone icon where it said
 * "Opslaan". Every field and choice stays.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {}, subscribe: () => {}, unsubscribe: () => {} }))
function stub (name) {
  return defineComponent({ name, props: ['modelValue', 'label', 'type', 'value', 'name', 'disabled', 'variant'], render() {
	return h('div', { class: name, 'data-label': this.label }, [this.$slots.icon?.(), this.$slots.default?.()])
} })
}
vi.mock('@nextcloud/vue', async () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcDateTimePicker: stub('NcDateTimePicker'), NcTextArea: stub('NcTextArea'), NcTextField: stub('NcTextField'), NcNoteCard: stub('NcNoteCard') }))
for (const c of ['NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcTextArea', 'NcTextField']) {
	vi.doMock(`@nextcloud/vue/components/${c}`, () => ({ default: stub(c) }))
}
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: defineComponent({ name: 'CnDialog', render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} }) }))

const { default: LogContactDialog } = await import('../../src/dialogs/LogContactDialog.vue')
const dutch = (key) => nl.translations[key] || key

describe('Contact vastleggen against DqZaakDialogen', () => {
	it('marks start time and caller optional and records with Vastleggen and a phone', () => {
		const w = mount(LogContactDialog, { props: { caseId: 'c-1' }, global: { mocks: { $route: { params: { id: 'c-1' } } } } })
		expect(dutch(w.find('[data-testid="log-contact-start"]').attributes('data-label'))).toBe('Starttijd (niet verplicht)')
		expect(dutch(w.find('[data-testid="log-contact-caller"]').attributes('data-label'))).toBe('Wie belde (niet verplicht)')
		const confirm = w.find('[data-testid="log-contact-confirm"]')
		expect(dutch(confirm.text())).toBe('Vastleggen')
		expect(confirm.find('.phone-log-outline-icon').exists()).toBe(true)
	})
})
