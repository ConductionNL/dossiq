// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The StUF audit-log detail dialog: the request envelope always, and the
 * response, the retries and the error only when the row carries them.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StufEnvelopeDialog from '../../src/dialogs/StufEnvelopeDialog.vue'

const STUBS = {
	NcDialog: { emits: ['closing'], template: '<div class="dialog" @click="$emit(\'closing\')"><slot /></div>' },
}

/**
 * Mount the dialog over one audit row.
 *
 * @param {object} row The audit-log row.
 * @return {object} The wrapper.
 */
function mountDialog(row) {
	return mount(StufEnvelopeDialog, { props: { row }, global: { stubs: STUBS } })
}

describe('StufEnvelopeDialog', () => {
	it('shows only the request envelope for a plain row', () => {
		const wrapper = mountDialog({ envelopeXml: '<ZKN:zakLk01/>' })

		const headings = wrapper.findAll('h4').map((h) => h.text())
		expect(headings).toEqual(['Request envelope'])
		expect(wrapper.find('pre').text()).toBe('<ZKN:zakLk01/>')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('says there is no envelope when the row has none', () => {
		expect(mountDialog({}).find('pre').text()).toBe('(no envelope)')
	})

	it('adds the response, the retries and the pretty-printed error when present', () => {
		const wrapper = mountDialog({
			envelopeXml: '<req/>',
			responseEnvelopeXml: '<Fo02/>',
			retries: [
				{ attempt: 1, timestamp: '2026-11-01T10:00:00Z', httpStatus: 503, durationMs: 1200 },
				{ attempt: 2, timestamp: '2026-11-01T10:00:05Z' },
			],
			fout: { code: 'StUF058', omschrijving: 'Proces voor afhandelen bericht geeft fout' },
		})

		expect(wrapper.findAll('h4').map((h) => h.text())).toEqual(['Request envelope', 'Response envelope', 'Retries', 'Error'])
		const pres = wrapper.findAll('pre').map((p) => p.text())
		expect(pres[1]).toBe('<Fo02/>')
		expect(JSON.parse(pres[2])).toEqual({ code: 'StUF058', omschrijving: 'Proces voor afhandelen bericht geeft fout' })
		expect(pres[2]).toContain('\n  "code"')

		const rows = wrapper.findAll('tbody tr').map((r) => r.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['1', '2026-11-01T10:00:00Z', '503', '1200'],
			['2', '2026-11-01T10:00:05Z', '—', '—'],
		])
	})

	it('hides the retries for an empty or non-list value', () => {
		expect(mountDialog({ retries: [] }).find('table').exists()).toBe(false)
		expect(mountDialog({ retries: 'none' }).find('table').exists()).toBe(false)
	})

	it('prints a value JSON cannot encode as a string', () => {
		const wrapper = mountDialog({})
		const cyclic = {}
		cyclic.self = cyclic

		expect(wrapper.vm.pretty(cyclic)).toBe('[object Object]')
		expect(wrapper.vm.pretty('plain')).toBe('"plain"')
	})

	it('closes when the dialog closes', async () => {
		const wrapper = mountDialog({})

		await wrapper.find('.dialog').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
