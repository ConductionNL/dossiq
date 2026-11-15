/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaken names a label filter on the Active line "Label: wijk-noord",
 * not the schema's own "Tags: wijk-noord".
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/cases-filter-labels-for-views/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8'))
const cases = read('src', 'manifest.json').pages.find((entry) => entry.id === 'Cases')
const register = read('lib', 'Settings', 'dossiq_register.json')
const nl = read('l10n', 'nl.json').translations

describe('the label chip on the cases list (DqZaken)', () => {
	it('names the tags filter Label, in Dutch too', () => {
		expect(cases.config.filterLabels.tags).toEqual({ label: 'Label' })
		expect(nl.Label).toBe('Label')
	})

	it('names a field the case carries', () => {
		expect(register.components.schemas.case.properties.tags).toBeDefined()
	})
})
