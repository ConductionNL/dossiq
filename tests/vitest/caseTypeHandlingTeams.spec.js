/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The handling teams of a case type (`handling.teams`) are edited where its
 * other required facts are: on the Case type card and in the create form.
 * The library edits a key inside an object property as a field of its own.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const list = pages.find((page) => page.id === 'CaseTypes').config
const detail = pages.find((page) => page.id === 'CaseTypeDetail').config

describe('case type handling teams', () => {
	it('are on the create form, after the responsible unit', () => {
		const fields = list.includeFields
		expect(fields).toContain('handling.teams')
		expect(fields.indexOf('handling.teams')).toBe(fields.indexOf('responsibleUnit') + 1)
		expect(list.fieldOverrides['handling.teams'].order).toBeGreaterThan(list.fieldOverrides.responsibleUnit.order)
		expect(list.fieldOverrides['handling.teams'].order).toBeLessThan(list.fieldOverrides.notificationDomain.order)
	})

	it('are on the Case type card', () => {
		const core = detail.widgets.find((widget) => widget.id === 'case-type-core').content.include
		expect(core).toContain('handling.teams')
	})
})
