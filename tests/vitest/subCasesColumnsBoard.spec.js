/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd "Deelzaken" (r29): the status by its name as a toned
 * pill (live drew the status type's uuid), the handler as a person under
 * "Behandelaar" (live drew the uid under "Toegewezen aan"), and the board's
 * footnote under the table.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const content = caseDetail.configAppend.widgets.find((entry) => entry.id === 'case-sub-cases-card').content
const column = (key) => content.columns.find((entry) => entry.key === key)

describe('the sub-cases table', () => {
	it('names the status as a toned pill', () => {
		expect(column('status')).toMatchObject({ formatter: 'statusTypeName', widget: 'badge', widgetProps: { resolve: { register: 'dossiq', schema: 'statusType', colorField: 'colour' } } })
	})

	it('shows the handler as a person under Behandelaar', () => {
		expect(column('assignee')).toMatchObject({ widget: 'avatar', widgetProps: { user: true } })
		expect(nl[column('assignee').label]).toBe('Behandelaar')
	})

	it('ends with the board footnote', () => {
		expect(nl[content.footnote]).toBe('Een deelzaak is een zaak, met een eigen nummer en termijn. Verwijderen kan pas als er geen deelzaak meer onder hangt.')
	})
})
