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
vi.mock('../../src/services/pipelinqCaseApi.js', () => ({ logContactMoment: vi.fn(() => Promise.resolve({ contactmoment: {} })), refusalOf: () => '', refusalSentence: () => '' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {}, subscribe: () => {}, unsubscribe: () => {} }))
function stub (name) {
  return defineComponent({ name, props: ['modelValue', 'label', 'type', 'value', 'name', 'disabled', 'variant', 'inputLabel', 'options', 'reduce'], render() {
	return h('div', { class: name, 'data-label': this.inputLabel || this.label, 'data-options': JSON.stringify((this.options || []).map((o) => o.value)) }, [this.$slots.icon?.(), this.$slots.default?.()])
} })
}
vi.mock('@nextcloud/vue', async () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcDateTimePicker: stub('NcDateTimePicker'), NcTextArea: stub('NcTextArea'), NcTextField: stub('NcTextField'), NcNoteCard: stub('NcNoteCard') }))
for (const c of ['NcButton', 'NcCheckboxRadioSwitch', 'NcDateTimePicker', 'NcSelect', 'NcTextArea', 'NcTextField']) {
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

	it('asks channel and direction as two optional selects on one row, saving the same values', async () => {
		const { logContactMoment } = await import('../../src/services/pipelinqCaseApi.js')
		const w = mount(LogContactDialog, { props: { caseId: 'c-1' }, global: { mocks: { $route: { params: { id: 'c-1' } } } } })
		expect(w.find('.log-contact__pair').exists()).toBe(true)
		expect(w.findAll('.log-contact__pair .NcSelect')).toHaveLength(2)
		expect(w.find('[type="radio"]').exists()).toBe(false)
		const channel = w.find('[data-testid="log-contact-channel"]')
		const direction = w.find('[data-testid="log-contact-direction"]')
		expect(dutch(channel.attributes('data-label'))).toBe('Kanaal (niet verplicht)')
		expect(dutch(direction.attributes('data-label'))).toBe('Richting (niet verplicht)')
		expect(JSON.parse(channel.attributes('data-options'))).toEqual(['phone', 'email', 'balie', 'brief', 'webformulier', 'chat', 'social_media'])
		expect(JSON.parse(direction.attributes('data-options'))).toEqual(['inbound', 'outbound'])
		await w.setData({ channel: 'email', direction: 'outbound', summary: 'Vraagt wanneer het besluit komt.' })
		await w.vm.save()
		expect(logContactMoment).toHaveBeenCalledWith('c-1', expect.objectContaining({ notificationChannel: 'email', direction: 'outbound' }))
	})
})
