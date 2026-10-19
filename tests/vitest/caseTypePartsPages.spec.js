/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type's statuses and fields open on a page of their own and can be
 * deleted from the list, so the manifest case-type page edits what the
 * retired admin-settings editor did.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
const register = require('../../lib/Settings/dossiq_register.json')
import fragment from '../../src/manifest.d/68-case-type-parts.json'
import manifest from '../../src/manifest.json'
import layout from '../../src/menu-layout.simple.json'
const schemas = register.components.schemas
const overlay = layout.pages.find((p) => p.id === 'CaseTypeDetail')
const appended = (id) => overlay.configAppend.widgets.find((w) => w.id === id)
const page = (id) => fragment.pages.find((p) => p.id === id)
const baseWidget = (id) => manifest.pages.find((p) => p.id === 'CaseTypeDetail').config.widgets.find((w) => w.id === id)

describe('the parts of a case type', () => {
	it.each([
		['case-type-statuses-list', 'StatusTypeDetail', 'statusType'],
		['case-type-fields', 'PropertyDefinitionDetail', 'propertyDefinition'],
	])('%s opens %s and deletes a row', (widgetId, pageId, schema) => {
		const content = appended(widgetId).content
		expect(content.rowRoute).toBe(pageId)
		expect(content.rowActions).toEqual([expect.objectContaining({ type: 'object-op', op: 'delete', destructive: true })])
		expect(page(pageId).config).toMatchObject({ register: 'dossiq', schema })
	})

	it('binds every field on the part pages to a property the schema declares', () => {
		for (const p of fragment.pages) {
			const properties = Object.keys(schemas[p.config.schema].properties)
			for (const widget of p.config.widgets) {
				for (const field of widget.content.include) {
					expect(properties, `${p.id}: ${field}`).toContain(field)
				}
			}
		}
	})

	it('groups the fields by category', () => {
		expect(appended('case-type-fields').content.groupBy).toBe('category')
	})

	it('edits the general fields the old editor had on the case type cards', () => {
		const properties = Object.keys(schemas.caseType.properties)
		const fields = [...baseWidget('case-type-core').content.include, ...baseWidget('case-type-terms').content.include]
		for (const field of ['subject', 'handlerAction', 'publicationRequired', 'maxExtensions', 'queueWarningDays', 'queueCriticalDays']) {
			expect(fields).toContain(field)
			expect(properties).toContain(field)
		}
	})
})
