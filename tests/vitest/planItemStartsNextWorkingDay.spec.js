/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Iets inplannen" opens with "Wanneer" at the next working day at 10:00
 * (DqUwWachtrij; Ruben, 23 Oct): the dialog reads the defaults endpoint as its
 * record, draws no summary row for it, and starts the field from it.
 *
 * @spec openspec/specs/my-work/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const ROOT = path.resolve(__dirname, '../..')

/**
 * The plan-item header action, wherever a page declares it.
 *
 * @return {object} The action.
 */
function planItem() {
	for (const page of manifest.pages) {
		const found = (page.config?.headerActions || []).find((action) => action.id === 'plan-item')
		if (found) {
			return found
		}
	}
	return null
}

describe('a planned item starts at the next working day', () => {
	const config = planItem().props.config

	it('reads the defaults endpoint as its record, without a summary row', () => {
		expect(config.summary).toEqual({ source: { url: '/apps/dossiq/api/personal-queue/planned-items/defaults' } })
	})

	it('starts When from it, and the reader can still change it', () => {
		const when = config.fields.find((field) => field.field === 'startsAt')
		expect(when).toMatchObject({ type: 'datetime', valuePath: 'startsAt' })
		expect(when.readOnly).toBeUndefined()
	})

	it('routes the defaults to their own controller', () => {
		const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
		expect(routes).toMatch(/'plannedItemDefaults#show',\s*'url' => '\/api\/personal-queue\/planned-items\/defaults',\s*'verb' => 'GET'/)
	})
})
