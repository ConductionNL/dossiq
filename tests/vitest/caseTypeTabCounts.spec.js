/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every case type board draws "Fasen en taken 6", "Regels 3" and
 * "Vertalingen 2": the tasks over all phases, the case type's rules and the
 * languages the case type is kept in.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const tabs = simple.configAppend.widgets.find((widget) => widget.id === 'case-type-panels').content.tabs
const tab = (id) => tabs.find((entry) => entry.id === id)

describe('the case type tab counts', () => {
	it('counts the tasks over all phases', () => {
		expect(tab('phases').countFrom).toEqual({ endpoint: '/apps/dossiq/api/case-types/@objectId/phases', field: 'taskCount' })
	})

	it('counts the case type\'s own rules, the "Als ... dan ..." rows of the Rules tab', () => {
		expect(tab('rules').countField).toBe('rules')
		expect(tab('rules').countFrom).toBeUndefined()
	})

	it('counts the languages the register keeps', () => {
		expect(tab('translations').countFrom).toEqual({ endpoint: '/apps/openregister/api/registers/dossiq', field: 'languages' })
	})
})
