// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * You group templates in folders: the Templates page shows a folder per
 * category its templates carry, All templates on top (REQ-CRS-39, row 11.23).
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/property-definition-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = readJson('src/manifest.json').pages.find((entry) => entry.id === 'WorkflowDefinitions')
const fragment = readJson('lib/Settings/register.d/99-template-category.json')

describe('the Templates page folders', () => {
	it('groups and filters the templates on their category, All templates on top', () => {
		expect(page.config.folderSidebar).toEqual({
			source: 'field',
			field: 'category',
			filterField: 'category',
			allLabel: 'All templates',
		})
	})

	it('shows the category beside the title', () => {
		const columns = page.config.columns
		expect(columns.indexOf('category')).toBe(columns.indexOf('title') + 1)
	})

	it('lists the same schema the folders group on', () => {
		expect(page.config.schema).toBe('workflowTemplate')
		expect(Object.keys(fragment.components.schemas)).toEqual(['workflowTemplate'])
	})

	it('makes the category a facetable string, so a folder exists for a category on a later page', () => {
		expect(fragment.components.schemas.workflowTemplate.properties.category).toMatchObject({
			type: 'string',
			facetable: true,
			title: 'Category',
		})
	})

	it('reads All templates in Dutch', () => {
		expect(readJson('l10n/nl.json').translations['All templates']).toBe('Alle sjablonen')
	})
})
