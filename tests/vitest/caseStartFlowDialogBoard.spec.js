// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Flow starten in the words of board DqZaakDialogen: the flows under the
 * legend "Deelproces", the line "Alleen flows die het zaaktype toestaat."
 * beneath them, and "Starten" where it said "Uitvoeren".
 *
 * @spec openspec/specs/case-dialogs/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

const flows = [
	{ id: 'f-1', title: 'Zienswijze derde vragen', description: 'Stuurt een brief en wacht 2 weken' },
	{ id: 'f-2', title: 'Verdaging aankondigen', description: 'Bericht aan de verzoeker' },
]
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { results: flows } })), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: () => {}, subscribe: () => {}, unsubscribe: () => {} }))
function stub(name) {
	return defineComponent({ name, props: ['modelValue', 'type', 'value', 'name', 'disabled', 'variant', 'size'], render() {
		return h('div', { class: name }, [this.$slots.icon?.(), this.$slots.default?.()])
	} })
}
for (const c of ['NcButton', 'NcCheckboxRadioSwitch', 'NcLoadingIcon']) {
	vi.doMock(`@nextcloud/vue/components/${c}`, () => ({ default: stub(c) }))
}
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: defineComponent({ name: 'CnDialog', render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} }) }))

const { default: CaseStartFlowDialog } = await import('../../src/dialogs/CaseStartFlowDialog.vue')
const dutch = (key) => nl.translations[key] || key

describe('Flow starten against DqZaakDialogen', () => {
	it('lists the flows under Deelproces, notes the case type limit and starts with Starten', async () => {
		const w = mount(CaseStartFlowDialog, { props: { caseId: 'c-1' } })
		await flushPromises()
		expect(dutch(w.find('legend').text())).toBe('Deelproces')
		expect(w.findAll('[data-testid="case-start-flow-list"] li')).toHaveLength(2)
		expect(w.find('[data-testid="case-start-flow-list"]').text()).toContain('Stuurt een brief en wacht 2 weken')
		expect(dutch(w.find('[data-testid="case-start-flow-note"]').text())).toBe('Alleen flows die het zaaktype toestaat.')
		expect(dutch(w.find('[data-testid="case-start-flow-confirm"]').text())).toBe('Starten')
	})
})
