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
		['case-type-results', 'ResultTypeDetail', 'resultType'],
		['case-type-roles', 'RoleTypeDetail', 'roleType'],
		['case-type-document-types', 'DocumentTypeDetail', 'documentType'],
		['case-type-decision-types', 'DecisionTypeDetail', 'decisionType'],
	])('%s opens %s and deletes a row', (widgetId, pageId, schema) => {
		const content = appended(widgetId).content
		expect(content.rowRoute).toBe(pageId)
		expect(content.rowActions).toEqual([expect.objectContaining({ type: 'object-op', op: 'delete', destructive: true })])
		expect(page(pageId).config).toMatchObject({ register: 'dossiq', schema })
		expect(appended(widgetId).content).toMatchObject({ register: 'dossiq', schema, filter: { caseType: '@objectId' } })
	})

	it('places results, document and decision types under statuses, roles under rights', () => {
		const tabs = appended('case-type-panels').content.tabs
		const holds = (label) => tabs.find((tab) => tab.label === label).widgetIds
		expect(holds('Statuses')).toEqual(['case-type-statuses-editor', 'case-type-results', 'case-type-document-types', 'case-type-decision-types', 'case-type-blueprint'])
		expect(holds('Rights')).toEqual(['case-type-rights', 'case-type-roles'])
		expect(holds('Phases and tasks')).not.toEqual(expect.arrayContaining(['case-type-document-types']))
	})

	it('cannot unpublish: isDraft is not an editable field on the case type card', () => {
		expect(baseWidget('case-type-core').content.include).not.toContain('isDraft')
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

	it('edits messages, the workflow and the rules in their rehosted editors, statuses and fields as lists', () => {
		const tabs = appended('case-type-panels').content.tabs
		const holds = (label) => tabs.find((tab) => tab.label === label).widgetIds
		expect(holds('Fields')).toEqual(expect.arrayContaining(['case-type-fields-editor']))
		expect(holds('Rules')).toEqual(['case-type-rules'])
		// The workflow canvas has a page of its own, CaseTypeFlow (case-type-page-follows-its-boards).
		expect(holds('Message templates')).toEqual(['case-type-messages-editor'])
		const types = overlay.configPatch.widgets
		expect(types['case-type-statuses-editor']).toBeUndefined()
		expect(types['case-type-fields-editor']?.type).toBeUndefined()
		expect(baseWidget('case-type-statuses-editor').type).toBe('object-list')
		expect(baseWidget('case-type-fields-editor').type).toBe('object-list')
		expect(types['case-type-messages-editor'].type).toBe('CaseTypeMessagesEditorWidget')
		expect(types['case-type-workflow-editor'].type).toBe('CaseTypeWorkflowEditorWidget')
		expect(types['case-type-rules'].type).toBe('CaseTypeRulesWidget')
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
