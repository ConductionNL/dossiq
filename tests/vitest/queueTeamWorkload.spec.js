/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTeamwachtrij: the queue's "Team today" card is the library's team
 * workload widget over the coordinator-only workload endpoint, counting each
 * handler's open cases against a capacity of twelve.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

// The team queue is the Cases page's `team-queue` view; its side cards are
// the view's overrides.
const manifest = readJson('src', 'manifest.json')
const card = manifest.pages
	.find((page) => page.id === 'Cases')
	.config.defaultSavedViews.find((view) => view.slug === 'team-queue')
	.overrides.sidePanel.find((widget) => widget.id === 'queue-team-today')

describe('the queue\'s Team today card', () => {
	it('is the library team workload widget over the workload endpoint', () => {
		expect(card.type).toBe('team-workload')
		expect(card.content).toEqual({
			source: {
				url: '/apps/dossiq/api/work-queue/workload',
				responsePath: 'handlers',
				userField: 'handler',
				countField: 'openCaseCount',
			},
			capacity: 12,
			countLabel: { one: '{count} case', other: '{count} cases' },
		})
	})

	it('has Dutch for its count labels', () => {
		const nl = readJson('l10n', 'nl.json').translations
		expect(nl['{count} case']).toBe('{count} zaak')
		expect(nl['{count} cases']).toBe('{count} zaken')
	})
})
