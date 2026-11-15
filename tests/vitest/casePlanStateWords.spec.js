/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak Zaakplan words a running item "Actief" (the plan widget's default
 * reads "In behandeling"); a reached milestone and an optional item take the
 * widget's own words, Bereikt and Optioneel.
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

describe('the case plan state words', () => {
	it('words a running item Actief', () => {
		expect(plan.type).toBe('plan')
		expect(nl[plan.content.stateLabels.active]).toBe('Actief')
	})
})
