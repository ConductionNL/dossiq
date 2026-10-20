/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list exports through the library's own Export menu.
 *
 * CnIndexPage draws the Export menu (CSV and Excel) when the page sets
 * `allowExport` and the schema is flagged `exportable`. A click sends the
 * browser to OpenRegister's export leaf for (dossiq, case) with the list's
 * own query, so OpenRegister serialises the file and enforces access as the
 * reader (ADR-022). Both flags are asserted here: without either one the
 * menu does not render and nothing says why.
 *
 * @spec openspec/changes/case-list-export-via-or-export-leaf/specs/case-list-export-via-or-export-leaf/spec.md
 */

import { buildExportUrl } from '@conduction/nextcloud-vue/src/utils/indexExportHelpers.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const cases = manifest.pages.find((entry) => entry.id === 'Cases')

describe('the Cases page offers the library export', () => {
	it('sets allowExport on the page', () => {
		expect(cases.config.allowExport).toBe(true)
	})

	it('flags the case schema exportable where OpenRegister keeps the flag', () => {
		expect(register.components.schemas.case.configuration.exportable).toBe(true)
	})

	it('mounts no app export component beside it', () => {
		expect(cases.actionsComponent).toBeUndefined()
		expect(registrySource).not.toContain('CaseListExportAction')
	})
})

describe('the export link the library builds for this page', () => {
	it('points at the (dossiq, case) OpenRegister export leaf', () => {
		const url = buildExportUrl('dossiq', 'case', {}, 'csv')
		expect(url).toMatch(/\/apps\/openregister\/api\/objects\/dossiq\/case\/export\?format=csv$/)
	})

	it('carries the list filters and drops the paging', () => {
		const url = buildExportUrl('dossiq', 'case', { status: 'open', _page: 3, _limit: 25 }, 'excel')
		const params = new URLSearchParams(url.split('?')[1])
		expect(params.get('format')).toBe('excel')
		expect(params.get('status')).toBe('open')
		expect(params.has('_page')).toBe(false)
		expect(params.has('_limit')).toBe(false)
	})
})
