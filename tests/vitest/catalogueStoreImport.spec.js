/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus' Uit de Store card has "Importeren" in its title row: the case
 * type import dialog, which takes a bundle file.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const store = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/64-catalogue.json'), 'utf8'))
	.pages[0].config.widgets.find((widget) => widget.id === 'catalogue-store')
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('the Store card import (DqCatalogus)', () => {
	it('opens the case type import dialog from the card title row', () => {
		const [importAction] = store.headerActions
		expect(nl[importAction.label]).toBe('Importeren')
		expect(importAction.action).toEqual({ type: 'open-modal', target: 'CaseTypeImportDialog' })
		expect(registry).toContain('CaseTypeImportDialog: {')
	})
})
