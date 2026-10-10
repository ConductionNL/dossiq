// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { columnsFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
/**
 * The admin case type list shows five columns, title first.
 *
 * 🔴 THE REAL SCHEMA IS READ, AND THE REAL COLUMN BUILDER RUNS. The defect was
 * the library's `columnsFromSchema()` doing exactly what it is documented to
 * do with a 96-property schema: a column per scalar property, alphabetical.
 * So the assertion runs that function over the register's own case type
 * schema (base file plus its `register.d` fragments), with the props the list
 * passes, rather than over a hand-written stand-in that already has 5 keys.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	CASE_TYPE_COLUMNS,
	orderedListSchema,
} from '../../src/utils/caseTypeColumns.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * The caseType schema as the register ships it, fragments merged in.
 *
 * @return {object} The schema.
 */
function caseTypeSchema() {
	const base = JSON.parse(
		fs.readFileSync(
			path.join(ROOT, 'lib/Settings/dossiq_register.json'),
			'utf8',
		),
	)
	const schema = structuredClone(base.components.schemas.caseType)
	const dir = path.join(ROOT, 'lib/Settings/register.d')
	for (const file of fs.existsSync(dir) ? fs.readdirSync(dir) : []) {
		if (!file.endsWith('.json')) {
			continue
		}
		const fragment = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'))
		const props = fragment?.components?.schemas?.caseType?.properties ?? {}
		Object.assign(schema.properties, props)
	}

	return schema
}

describe('the admin case type list', () => {
	it('starts from a schema with far more properties than it shows', () => {
		// The control: without it, five columns could just mean five properties.
		expect(Object.keys(caseTypeSchema().properties).length).toBeGreaterThan(50)
	})

	it('draws exactly the five chosen columns, title first', () => {
		const columns = columnsFromSchema(orderedListSchema(caseTypeSchema()), {
			include: [...CASE_TYPE_COLUMNS],
		})

		expect(columns.map((column) => column.key)).toEqual([
			'title',
			'identifier',
			'isDraft',
			'processingDeadline',
			'validFrom',
		])
	})

	it('leaves the cached schema alone', () => {
		const schema = caseTypeSchema()
		orderedListSchema(schema)

		expect(schema.properties.title.order).toBeUndefined()
	})

	it('is what CaseTypeList hands the table', () => {
		const source = fs.readFileSync(
			path.join(ROOT, 'src/views/settings/CaseTypeList.vue'),
			'utf8',
		)

		expect(source).toContain(':includeColumns="columns"')
		expect(source).toContain(':schema="listSchema"')
	})
})
