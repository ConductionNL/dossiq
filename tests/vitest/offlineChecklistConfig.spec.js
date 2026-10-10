/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The offline checklist leaf's config names things that exist.
 *
 * Every key in OFFLINE_CHECKLIST_CONFIG is a claim about dossiq: a schema,
 * a property on it, a word in its enum, a route, a request key the endpoint
 * reads. The leaf cannot tell when one of them goes stale; it just shows an
 * empty checklist or queues runs that never land. So each claim is checked
 * against the file that owns it.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/mobiel-inspectie-offline/spec.md#requirement-offline-checklist-completion-and-storage
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { OFFLINE_CHECKLIST_CONFIG as config } from '../../src/integrations/offlineChecklistConfig.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const register = JSON.parse(read('lib', 'Settings', 'dossiq_register.json'))
const offlineFragment = JSON.parse(
	read('lib', 'Settings', 'register.d', '40-mobiel-inspectie-offline.json'),
)
const schemas = {
	...register.components.schemas,
	...offlineFragment.components.schemas,
}

describe('offline checklist config', () => {
	it('reads planned items from a schema carrying every planning field', () => {
		const planned = schemas[config.plannedSchema]
		expect(planned).toBeTruthy()
		for (const field of [
			config.templateRefField,
			config.assigneeField,
			config.dateField,
			config.titleField,
		]) {
			expect(Object.keys(planned.properties)).toContain(field)
		}
	})

	it('reads templates from the one template schema, through its own item properties', () => {
		const template = schemas[config.referenceSchema]
		expect(template).toBeTruthy()
		const item =
			template.properties[config.sectionsField].items.properties.items.items
				.properties
		for (const field of [
			config.itemKeyField,
			config.itemTextField,
			config.itemTypeField,
			config.photoRequiredField,
		]) {
			expect(Object.keys(item)).toContain(field)
		}
		for (const ownType of Object.keys(config.itemTypeMap)) {
			expect(item[config.itemTypeField].enum).toContain(ownType)
		}
		expect(item[config.photoRequiredField].enum).toContain(
			config.photoRequiredValue,
		)
	})

	it('replays to a route dossiq declares, filled from a planned item field', () => {
		const prefix = '/apps/dossiq'
		expect(config.resultEndpoint.startsWith(prefix)).toBe(true)
		const routePath = config.resultEndpoint
			.slice(prefix.length)
			.replace(/\{[^}]+\}/g, '{id}')
		expect(read('appinfo', 'routes.php')).toMatch(
			new RegExp(
				`'url' => '${routePath.replace(/[{}]/g, '\\$&')}',\\s*'verb' => 'POST'`,
			),
		)

		const placeholders = [...config.resultEndpoint.matchAll(/\{([^}]+)\}/g)].map(
			(m) => m[1],
		)
		for (const field of placeholders) {
			expect(Object.keys(schemas[config.plannedSchema].properties)).toContain(
				field,
			)
		}
	})

	it('names the template and the planned item under the keys the endpoint reads', () => {
		expect(
			read('lib', 'Controller', 'InspectionChecklistController.php'),
		).toContain(`$params['${config.resultTemplateParam}']`)
		expect(
			read('lib', 'Service', 'Inspection', 'InspectionRunService.php'),
		).toMatch(
			new RegExp(`METADATA = \\[[^\\]]*'${config.resultPlannedItemParam}'`),
		)
	})

	it('writes no result object', () => {
		expect(config.resultSchema).toBe('')
	})
})
