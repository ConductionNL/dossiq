/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's Inrichten card: a bar of how far the set-up is, the seven steps
 * as a checklist in three columns, and "5 van 7 stappen klaar" in the header.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const setup = config.widgets.find((widget) => widget.id === 'organisation-onboarding').content
const nl = read('l10n/nl.json').translations

describe('organisation set-up checklist (DqTenant)', () => {
	it('draws the steps as a checklist with a progress bar, three to a row', () => {
		expect(setup.presentation).toBe('checklist')
		expect(setup.done).toEqual({ field: 'status', op: 'eq', value: 'completed' })
		expect(setup.progress).toBe(true)
		expect(setup.columns).toBe(3)
	})

	it('counts the done steps in its header, once', () => {
		expect(setup.headerSubtitle).toBeUndefined()
		expect(nl[setup.headerNote]).toBe('{count:status=completed} van {total} stappen klaar')
	})

	it('keeps every step named', () => {
		expect(Object.keys(setup.valueLabels.step)).toHaveLength(7)
	})
})
