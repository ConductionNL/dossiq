/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeVelden: the Fields tab ends with what a case type declares
 * about forms and receipt, and the package a shipped type came from.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const widget = (id) => page.configAppend.widgets.find((w) => w.id === id)

describe('the Fields tab cards', () => {
	it('sits under the field rules', () => {
		const panels = widget('case-type-panels').content.tabs.find((t) => t.id === 'fields')
		expect(panels.widgetIds).toEqual(['case-type-fields-editor', 'case-type-field-rules', 'case-type-forms-receipt', 'case-type-package'])
	})

	it('reads the form and the acknowledgement off the case type', () => {
		const paths = widget('case-type-forms-receipt').content.fields.map((f) => f.path)
		expect(paths).toEqual(['intakeFormRef', 'acknowledgement.enabled'])
	})

	it('shows the package card only on a case type a package placed', () => {
		expect(widget('case-type-package').visibleWhen).toEqual({ field: 'package', op: 'notEmpty' })
	})
})
