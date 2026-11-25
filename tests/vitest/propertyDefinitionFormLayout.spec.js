/**
 * A case type's question carries where it sits on the case form (`order`), the
 * heading it sits under (`group`) and the example text in its empty input
 * (`placeholder`), set on the attribute catalogue form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/competitor-parity-2026-09/tasks.md#15
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))
const register = read('lib/Settings/dossiq_register.json')
const manifest = read('src/manifest.json')
const definition = register.components.schemas.propertyDefinition.properties
const extendsForm = register.components.schemas.case.properties.caseType['x-openregister-extends-form']

describe('propertyDefinition form layout fields', () => {
	it('declares order as an integer and group and placeholder as short strings', () => {
		expect(definition.order.type).toBe('integer')
		expect(definition.group).toMatchObject({ type: 'string', maxLength: 255 })
		expect(definition.placeholder).toMatchObject({ type: 'string', maxLength: 255 })
		for (const key of ['order', 'group', 'placeholder']) {
			expect(definition[key].title).toBeTruthy()
			expect(definition[key].description).toBeTruthy()
			expect(register.components.schemas.propertyDefinition.required || []).not.toContain(key)
		}
	})

	it('does not map them onto the case form until OpenRegister accepts the roles', () => {
		// OpenRegister refuses an extends-form role it does not know, and one
		// refusal rejects the whole case schema at import
		// (ShippedDeclarationsOpenRegisterAcceptsTest). It accepts only
		// hideOnCreate as a form role today, so sort, group and placeholder
		// are mapped once it learns them.
		expect(extendsForm.definitions.schema).toBe('propertyDefinition')
		for (const role of ['sort', 'group', 'placeholder']) {
			expect(extendsForm.map).not.toHaveProperty(role)
		}
	})

	it('lets an administrator set them on the attribute catalogue form', () => {
		const page = manifest.pages.find((candidate) => candidate.id === 'PropertyDefinitions')
		expect(page.config.includeFields).toEqual(expect.arrayContaining(['order', 'group', 'placeholder']))
	})
})
