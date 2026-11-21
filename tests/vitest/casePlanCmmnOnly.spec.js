/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The adaptive case plan shows on a CMMN case only.
 *
 * `cmmn-case-plan` reads OpenRegister's case layer on /api/cases/{id}. A case
 * of a BPMN case type has no plan there, so without a condition the page asks
 * for one on every case and OpenRegister answers 404.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#scenario-a-bpmn-case-is-untouched
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')

describe('the adaptive case plan', () => {
	it('is shown only when the case type is handled by the case layer', () => {
		const widget = caseDetail.config.widgets.find(
			(entry) => entry.id === 'cmmn-case-plan',
		)

		expect(widget, 'the widget is declared').toBeTruthy()
		expect(widget.visibleWhen).toEqual({
			field: '@context.caseType.handlingModel',
			op: 'eq',
			value: 'cmmn',
		})
	})

	it('reads the case type it asks about', () => {
		expect(caseDetail.config.visibilityContext.caseType).toEqual(
			expect.objectContaining({ field: 'caseType', schema: 'caseType' }),
		)
	})
})
