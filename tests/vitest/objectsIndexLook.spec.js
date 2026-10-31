/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Objects index in the All cases look, over the case object schema.
 *
 * @spec openspec/changes/objects-index-like-cases/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const page = manifest.pages.find((p) => p.id === 'CaseObjects')

describe('the Objects index', () => {
	it('is an index page over the case object schema', () => {
		expect(page.type).toBe('index')
		expect(page.config).toMatchObject({ register: 'dossiq', schema: 'caseObject', allowSavedViews: true })
		expect(page.config.folderSidebar).toMatchObject({ source: 'field', field: 'objectType', allLabel: 'All objects' })
	})

	it('names its add button itself, so it never reads the generic Add Item', () => {
		expect(page.config.addLabel).toBe('Add case object')
		expect(nl['Add case object']).toBe('Zaakobject toevoegen')
	})

	it('carries the All cases look: search placeholder, sidebar count, export and a table-only view switch', () => {
		expect(page.config.searchPlaceholder).toBe('Search by object type, identification or description')
		expect(page.config.sidebar).toMatchObject({ enabled: true, showMetadata: true, subtitle: { singular: '{total} object, {shown} on this page', plural: '{total} objects, {shown} on this page' } })
		expect(page.config.allowExport).toBe(true)
		expect(page.config.viewModes).toEqual(['table'])
		expect(page.config.viewSwitch).toEqual(['table'])
	})

	it('keeps the View case row action', () => {
		expect(page.config.actions).toEqual([expect.objectContaining({ id: 'view-case', route: 'CaseDetail', params: { id: '{case}' } })])
	})
})
