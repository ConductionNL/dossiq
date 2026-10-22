/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus draws the case type folders under "Case types" in the folder
 * card. A folder is a case type's `category`, the organisation's own word, so
 * the menu reads them from the category facet instead of naming any.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/64-catalogue.json'), 'utf8'))
const page = fragment.pages.find((entry) => entry.id === 'Catalogue')
const menu = page.config.widgets.find((widget) => widget.id === 'catalogue-folders')
const caseTypes = menu.content.items.find((item) => item.label === 'Case types')

describe('the catalogue\'s case type folders', () => {
	it('come from the case types\' category facet', () => {
		expect(caseTypes.childrenFrom).toEqual({
			register: 'dossiq',
			schema: 'caseType',
			field: 'category',
			url: '/index.php/apps/dossiq/settings/case-types?category={value}',
		})
	})

	it('name no folder in the manifest', () => {
		expect(caseTypes.children).toBeUndefined()
	})

	it('open the case types index on the folder its own sidebar filters by', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const index = manifest.pages.find((entry) => entry.id === 'CaseTypes')
		expect(index.config.folderSidebar.filterField).toBe('category')
	})
})
