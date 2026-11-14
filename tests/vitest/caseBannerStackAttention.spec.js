// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The attention strip in the banner row draws the markers and the risk
 * assessment the case carries.
 *
 * DQ-BUGS: CaseBannerStack handed the attention panel only `objectId`, so the
 * markers and the assessment, which are read off the loaded case, never
 * rendered on the live page. The row is library config now: a `sections`
 * widget whose children render through CnDetailWidgetHost, which binds the
 * loaded case as `objectData` to every child (nextcloud-vue CnSectionsWidget).
 * What keeps the fix is that the attention child reads both off the case it
 * is handed, which this asserts on the declaration.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/manifest.json'), 'utf8'))
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const widget = (id) => caseDetail.config.widgets.find((entry) => entry.id === id)

describe('the banner row', () => {
	it('draws the markers and the risk assessment the case carries', () => {
		const stack = widget('case-banner-stack')
		expect(stack.type).toBe('sections')
		expect(stack.content.sections.map((section) => section.widgetId)).toContain('case-attention')

		const attention = widget('case-attention')
		expect(attention.type).toBe('attention-flag')
		// Read off the case the host binds, not fetched: a declaration that
		// dropped these would draw neither, which is the bug this guards.
		expect(attention.content.markersPath).toBe('attentionMarkers')
		expect(attention.content.assessment.path).toBe('riskAssessment')
	})
})
