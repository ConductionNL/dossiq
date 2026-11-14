// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ruwe gegevens as board DqZaakDialogen draws it: the subtitle once, the
 * line on who it is for and that opening it is logged, and Annuleren beside
 * Kopiëren, which puts the JSON on the clipboard.
 *
 * @spec openspec/specs/case-dialogs/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

const stored = { identifier: '2026-0082', title: 'Verlichting fietspad Lindelaan' }
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: stored })) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
function stub(name) {
	return defineComponent({ name, props: ['variant', 'disabled', 'size'], render() {
		return h('div', { class: name }, [this.$slots.icon?.(), this.$slots.default?.()])
	} })
}
for (const c of ['NcButton', 'NcLoadingIcon']) {
	vi.doMock(`@nextcloud/vue/components/${c}`, () => ({ default: stub(c) }))
}
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: defineComponent({ name: 'CnDialog', props: ['name', 'subtitle', 'size'], render() {
	return h('div', [h('p', { class: 'subtitle' }, this.subtitle), this.$slots.default?.(), this.$slots.actions?.()])
} }) }))

const { default: CaseRawDataDialog } = await import('../../src/dialogs/CaseRawDataDialog.vue')
const dutch = (key) => nl.translations[key] || key

describe('Ruwe gegevens against DqZaakDialogen', () => {
	it('says the subtitle once, names who it is for, and copies the JSON', async () => {
		const writeText = vi.fn(() => Promise.resolve())
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const w = mount(CaseRawDataDialog, { props: { caseId: 'c-1' } })
		await flushPromises()
		expect(w.text().match(/nothing renamed/g)).toHaveLength(1)
		expect(dutch(w.find('[data-testid="case-raw-data-note"]').text())).toBe('Alleen voor beheerders. Lezen kost niets, maar het openen komt in het verwerkingenlog.')
		expect(dutch(w.find('[data-testid="case-raw-data-close"]').text())).toBe('Annuleren')
		const copy = w.find('[data-testid="case-raw-data-copy"]')
		expect(dutch(copy.text())).toBe('Kopiëren')
		await w.vm.copy()
		expect(writeText).toHaveBeenCalledWith(JSON.stringify(stored, null, 2))
		expect(w.find('[data-testid="case-raw-data-copied"]').exists()).toBe(true)
	})
})
