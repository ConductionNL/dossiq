/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case Status card says where the case stands in one line, with the time
 * in the status under it, as DqZaak draws it; the withheld moves stay off it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')

describe('the case Status card', () => {
	it('leaves the withheld moves out', () => {
		expect(simple.configPatch.widgets['case-status-declaration'].content.withheld).toBe(false)
	})

	it('keeps the base widget\'s endpoint and labels, since a patch replaces content whole', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const base = manifest.pages.find((page) => page.id === 'CaseDetail').config.widgets.find((widget) => widget.id === 'case-status-declaration').content
		expect(simple.configPatch.widgets['case-status-declaration'].content).toEqual({ ...base, withheld: false })
	})
})
