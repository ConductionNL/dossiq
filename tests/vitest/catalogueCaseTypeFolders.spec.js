/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus drew the case type folders under "Case types". With the
 * catalogue gone as a navigation level the folders are the Case types page's
 * own folder sidebar, on the same `category` facet, so an old catalogue
 * folder link (`?category=`) still opens the same folder.
 *
 * @spec openspec/changes/advanced-holds-the-catalogue-folders/specs/settings-navigation/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

describe('the case type folders', () => {
	it('are the case types index\'s folder sidebar, on the category facet', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const index = manifest.pages.find((entry) => entry.id === 'CaseTypes')
		expect(index.config.folderSidebar.filterField).toBe('category')
	})
})
