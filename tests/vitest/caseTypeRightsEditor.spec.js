/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's Rights tab edits the rights rows OpenRegister enforces:
 * a role, a department (own team, every department or one by name), a
 * confidentiality level, the actions, the statuses the edit rights hold in,
 * and what the role may do with documents.
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const rights = page.configAppend.widgets.find((widget) => widget.id === 'case-type-rights').content
const fields = Object.fromEntries(rights.add.fields.map((field) => [field.key, field]))
const caseType = read('lib/Settings/register.d/99-case-type-rights-enforced.json').components.schemas.caseType.properties.rightsMatrix.items.properties
const nl = read('l10n/nl.json').translations

describe('the Rights tab editor (case-type-rights-enforced)', () => {
	it('adds, edits and removes rows', () => {
		expect(rights.readOnly).toBeUndefined()
		expect(rights.edit).toBeTruthy()
		expect(rights.remove).toBeTruthy()
	})

	it('offers own team and every department before the departments of the roles', () => {
		expect(fields.department.options.map((option) => option.value)).toEqual(['$ownTeam', '*'])
		expect(fields.department.optionsSource).toEqual(expect.objectContaining({ valuePath: 'department', path: 'results' }))
		expect(nl[fields.department.options[0].label]).toBe('Eigen team')
	})

	it('picks the statuses from the case type and the documents from the enum', () => {
		expect(fields.statuses).toEqual(expect.objectContaining({ type: 'multiselect' }))
		expect(fields.statuses.optionsSource.url).toBe('/apps/dossiq/api/case-types/@objectId/blueprint')
		expect(fields.documentActions.options.map((option) => option.value)).toEqual(caseType.documentActions.items.enum)
	})

	it('words the statuses by name in the table', () => {
		expect(rights.columns.find((column) => column.path === 'statuses').labelsFrom).toBe('statuses')
	})

	it('translates every label it adds', () => {
		for (const field of rights.add.fields) {
			expect(nl[field.label], field.label).toBeTruthy()
		}
		for (const column of rights.columns) {
			expect(nl[column.header], column.header).toBeTruthy()
		}
	})
})
