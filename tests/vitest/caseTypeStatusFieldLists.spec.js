/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type's statuses and fields as manifest config over the library's
 * object list, replacing the editors rehosted from the retired admin page.
 * What the config has to keep from those editors: a reorder that writes only
 * `order`, an edit that writes only what it changed, the checklist and field
 * rules with an optional condition, the vocabulary-typed field form with its
 * choice list, and every field bound to a property the schema declares.
 *
 * @spec openspec/changes/case-type-status-field-lists-as-config/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
const register = require('../../lib/Settings/dossiq_register.json')
import manifest from '../../src/manifest.json'
const schemas = register.components.schemas
const widget = (id) => manifest.pages.find((p) => p.id === 'CaseTypeDetail').config.widgets.find((w) => w.id === id)
const statuses = widget('case-type-statuses-editor').content
const fields = widget('case-type-fields-editor').content

describe('the statuses list', () => {
	it('lists this case type\'s statuses in their order and reorders by writing order', () => {
		expect(statuses).toMatchObject({ register: 'dossiq', schema: 'statusType', filter: { caseType: '@objectId' }, reorder: { field: 'order' }, editable: true })
	})

	it('keeps order unique among the type\'s statuses', () => {
		expect(statuses.formFieldOverrides.order.unique).toEqual({ filter: { caseType: '@objectId' } })
	})

	it('edits the checklist and the field rules in place, tidied on save', () => {
		const o = statuses.formFieldOverrides
		expect(o.checklist).toMatchObject({ widget: 'sub-objects', newItem: { required: false }, pruneWhen: { blank: ['title'] } })
		expect(o.fieldRules).toMatchObject({ widget: 'sub-objects', newItem: { rule: 'required' }, pruneWhen: { invalid: true } })
		expect(o.fieldRules.itemOverrides.condition).toMatchObject({ widget: 'object-switch', defaults: { kind: 'fieldPresent' } })
		expect(o.fieldRules.itemOverrides['condition.value'].visibleWhen).toEqual({ field: 'condition.kind', equals: 'fieldEquals' })
		expect(o.fieldRules.itemOverrides.groups.widget).toBe('group-multiselect')
	})

	it('picks a rule\'s field from this type\'s own fields', () => {
		expect(statuses.formFieldOverrides.fieldRules.itemOverrides.field.optionsFrom).toMatchObject({ schema: 'propertyDefinition', filter: { caseType: '@objectId' }, valueField: 'name' })
	})

	it('draws a swatch for every colour the schema allows', () => {
		const swatches = statuses.formFieldOverrides.colour.optionSwatches
		expect(Object.keys(swatches).sort()).toEqual([...schemas.statusType.properties.colour.enum].sort())
	})
})

describe('the fields list', () => {
	it('types a field against the property vocabulary, grouped by category', () => {
		expect(fields.formFieldOverrides.propertyType.optionsFrom).toMatchObject({ url: '/apps/openregister/api/schemas/property-vocabulary', responsePath: 'types', valueField: 'type', groupField: 'category' })
	})

	it('offers the formats of the chosen type, and none when it has none', () => {
		expect(fields.formFieldOverrides.format.optionsFrom).toMatchObject({ where: { type: '@answer.propertyType' }, itemsPath: 'formats', hideWhenEmpty: true })
		expect(fields.formFieldOverrides.format.clearWhenHidden).toBe(true)
	})

	it('keeps a choice list that is not stored and requires choices unless a scheme is set', () => {
		const o = fields.formFieldOverrides
		expect(o.choiceList).toMatchObject({ stored: false, widget: 'switch' })
		expect(o.enumValues).toMatchObject({ widget: 'lines', clearWhenHidden: true })
		expect(o.enumValues.requiredWhen).toEqual({ all: [{ field: 'choiceList', truthy: true }, { field: 'conceptScheme', empty: true }] })
	})
})

describe('both forms', () => {
	it.each([
		['statuses', statuses, 'statusType'],
		['fields', fields, 'propertyDefinition'],
	])('the %s form binds every field to a property the schema declares, or to one it does not store', (_name, content, schema) => {
		const properties = Object.keys(schemas[schema].properties)
		for (const key of content.formIncludeFields) {
			const stored = content.formFieldOverrides[key]?.stored !== false
			expect(stored ? properties : [key], `${schema}: ${key}`).toContain(key)
		}
		for (const key of Object.keys(content.formFieldOverrides)) {
			expect(content.formIncludeFields, `${schema}: override ${key}`).toContain(key)
		}
	})

	it('deletes a row from the list', () => {
		for (const content of [statuses, fields]) {
			expect(content.rowActions).toEqual([expect.objectContaining({ type: 'object-op', op: 'delete', destructive: true })])
		}
	})
})
