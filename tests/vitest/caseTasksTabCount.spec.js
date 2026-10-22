/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every case board draws the tasks tab as "Taken 4": the number of tasks on
 * the case, open and done, as the task table on that tab lists them.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const tab = simple.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'Tasks')
const tasksCard = simple.configAppend.widgets.find((widget) => widget.id === 'case-tasks-card')

describe('the case tasks tab', () => {
	it('counts the tasks on the case, open and done', () => {
		expect(tab.countFrom).toEqual({ endpoint: '/apps/openregister/api/flow-tasks?objectUuid=@objectId&scope=all', field: 'total' })
		expect(tasksCard.content.includeDone).toBe(true)
	})
})
