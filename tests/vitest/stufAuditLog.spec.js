// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The StUF message log on the admin page, a CnIndexPage over dossiq's
 * /api/stuf/messages. Everything the hand-written table did must still be
 * there: narrowing by endpoint, message type and status, the envelope
 * inspector per row, and a CSV of the rows.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import StufEnvelopeDialog from '../../src/dialogs/StufEnvelopeDialog.vue'
import { stufAuditIndex } from '../../src/views/settings/adminLists.js'

const declaration = stufAuditIndex((app, text) => text)
const adminRoot = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

describe('the StUF message log', () => {
	it('reads the endpoint, 100 rows at a time, searched by endpoint id', () => {
		expect(declaration.entitySource).toBe('endpoint')
		expect(declaration.sourceConfig).toMatchObject({ url: '/apps/dossiq/api/stuf/messages', rowsPath: 'items', searchParam: 'endpointId', limit: 100 })
		expect(declaration.inlineSearch).toBe(true)
	})

	it('narrows by status with a tab each and by message type with a strip', () => {
		expect(declaration.quickFilters.map((tab) => tab.filter.status ?? null)).toEqual([null, 'verzonden', 'bevestigd', 'fout', 'wacht_op_retry'])
		expect(declaration.filterStrip.field).toBe('messageKind')
		expect(declaration.filterStrip.options.map((option) => option.value)).toEqual(['Lk01', 'Lk02', 'Lk03', 'Bv01', 'Lv01', 'La01', 'Du01', 'Fo02'])
		expect(declaration.sourceConfig.searchFields).toEqual({ messageKind: { param: 'messageKind', single: true } })
	})

	it('reloads and downloads the rows as stuf-audit-log.csv, reference and case included', () => {
		expect(declaration.headerButtons.map((button) => button.action)).toEqual(['refresh', 'export'])
		expect(declaration.title).toBe('StUF audit log')
		expect(declaration.columns.map((col) => col.key)).toEqual(expect.arrayContaining(['sentOn', 'direction', 'messageKind', 'role', 'status', 'httpStatus', 'durationMs', 'referenceNumber', 'caseIdentification']))
	})

	it('opens the envelope of a row in the inspector the admin page hosts', () => {
		expect(declaration.actions).toEqual([expect.objectContaining({ id: 'inspect', type: 'open-modal', target: 'StufEnvelopeDialog' })])
		expect(adminRoot).toMatch(/const ADMIN_MODALS = \{[^}]*\bStufEnvelopeDialog,/)
		expect(adminRoot).toContain('<CnIndexPage v-if="storesReady" v-bind="stufAuditIndex" />')
	})

	it('shows the envelope, the answer and the retries of the row it was opened on', () => {
		const wrapper = mount(StufEnvelopeDialog, {
			props: { object: { envelopeXml: '<soap/>', responseEnvelopeXml: '<answer/>', retries: [{ at: '2026-10-01', httpStatus: 503 }] } },
			global: { stubs: { NcDialog: { render() { return h('div', this.$slots.default()) } } } },
		})
		expect(wrapper.text()).toContain('<soap/>')
		expect(wrapper.text()).toContain('<answer/>')
		expect(wrapper.text()).toContain('503')
	})
})
