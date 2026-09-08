/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What identifies a case: its number, its tags and its statutory fields.
 *
 * Every assertion here guards a rule that fails SILENTLY. A `readOnly` schema
 * property is dropped from a data widget outright rather than rendered
 * greyed-out, a `visible: false` property is dropped from every surface at
 * once, an icon that is not in `src/icons.js` renders nothing rather than a
 * fallback glyph, and a sidebar tab whose widget type is not a registry type
 * renders an empty panel. None of those raise, so the page just quietly holds
 * less than the manifest says it does.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The widget entry.
 */
const widget = (id) =>
	caseDetail().config.widgets.find((entry) => entry.id === id)

describe('CaseDetail: the case number', () => {
	it('shows the number and refuses to let anyone type it', () => {
		const core = widget('case-core')
		expect(core.content.include).toContain('identifier')

		const override = core.content.overrides.identifier
		// `readOnly: false` is not a licence to edit — it re-admits a
		// schema-readOnly property to the grid, which fieldsFromSchema
		// otherwise filters out before any override is read. `editable: false`
		// is what keeps it read-only. Drop either half and the field is wrong
		// in a different direction: missing, or typeable.
		expect(override.readOnly).toBe(false)
		expect(override.editable).toBe(false)
	})

	it('never offers a number field on the New case form', () => {
		const dashboard = manifest.pages.find((page) => page.id === 'Dashboard')
		const newCase = dashboard.config.headerActions.find(
			(action) => action.id === 'new-case',
		)
		expect(newCase.includeFields).not.toContain('identifier')
		expect(Object.keys(newCase.props ?? {})).not.toContain('identifier')
	})
})
