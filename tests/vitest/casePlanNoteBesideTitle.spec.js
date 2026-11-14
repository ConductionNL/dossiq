/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak Zaakplan draws "Mijlpalen en onderdelen ..." at the right of the
 * title row, not as a line under it.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const plan = caseDetail.configAppend.widgets.find((entry) => entry.id === 'case-plan-card')

describe('the case plan note', () => {
	it('sits beside the title, translated', () => {
		expect(nl[plan.content.headerNote]).toBe('Mijlpalen en onderdelen van deze zaak')
	})

	it('draws no line under the title', () => {
		expect(plan.subtitle).toBeUndefined()
	})
})
