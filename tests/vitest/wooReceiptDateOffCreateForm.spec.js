// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Woo "Ontvangstdatum" question stays off the create form.
 *
 * dossiq answers `receiptDate` itself when a Woo case is created (#3653), so
 * the form that creates the case must not ask for it. The question carries
 * `hideOnCreate: true`, which @conduction/nextcloud-vue 2.79.0 reads when it
 * turns definition records into form fields. Both places the Woo case type is
 * seeded from carry the flag, and the propertyDefinition schema declares it,
 * because OpenRegister drops a property the schema does not know.
 *
 * @spec openspec/specs/property-definition-management/spec.md
 */
import { propertiesFromDefinitions } from '@conduction/nextcloud-vue/src/utils/dynamicProperties.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path segments under the repository root.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const register = readJson('lib', 'Settings', 'dossiq_register.json')
const definitionSchema = register.components.schemas.propertyDefinition
const extendsForm =
	register.components.schemas.case.properties.caseType[
		'x-openregister-extends-form'
	]

const seeded = readJson(
	'lib',
	'Settings',
	'register.d',
	'81-woo-verzoek.json',
).components.objects.filter((row) => row['@self']?.schema === 'propertyDefinition')
const template = readJson(
	'lib',
	'Settings',
	'templates',
	'woo-verzoek.json',
).propertyDefinitions

/**
 * The receiptDate definition among a list of definitions.
 *
 * @param {Array<object>} rows Definition records.
 * @return {object|undefined} The receiptDate definition.
 */
function receiptOf(rows) {
	return rows.find((row) => row.name === 'receiptDate')
}

describe('the Woo receipt date is off the create form', () => {
	it('is declared on the propertyDefinition schema, so OpenRegister keeps it', () => {
		expect(definitionSchema.properties.hideOnCreate).toMatchObject({
			type: 'boolean',
			default: false,
		})
	})

	it('is set on the seeded Woo case type', () => {
		expect(receiptOf(seeded)).toMatchObject({ hideOnCreate: true })
	})

	it('is set on the Woo case type template', () => {
		expect(receiptOf(template)).toMatchObject({ hideOnCreate: true })
	})

	it('is the only Woo question kept off the create form', () => {
		for (const rows of [seeded, template]) {
			const hidden = rows
				.filter((row) => row.hideOnCreate === true)
				.map((row) => row.name)
			expect(hidden).toEqual(['receiptDate'])
		}
	})

	it('leaves the create form and stays on the edit form, through the case form map', () => {
		const records = seeded.map((row, index) => ({ ...row, id: `def-${index}` }))
		const receiptId = records.find((row) => row.name === 'receiptDate').id
		const key = `x-prop:${receiptId}`

		const create = propertiesFromDefinitions(records, extendsForm, {
			create: true,
		})
		const edit = propertiesFromDefinitions(records, extendsForm, {
			create: false,
		})

		expect(Object.keys(create.properties)).not.toContain(key)
		expect(Object.keys(create.properties)).toHaveLength(records.length - 1)
		expect(Object.keys(edit.properties)).toContain(key)
	})
})
