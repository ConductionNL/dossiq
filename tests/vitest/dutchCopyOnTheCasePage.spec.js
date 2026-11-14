// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The English that a Dutch reader met on the review instance (10 Oct, finding
 * F4), each run through the catalogue it already had or now has.
 *
 * The case's tabs and the panels under them were drawn as written by the
 * library, so "Overview", "Documents" and "Core case data" stayed English
 * although nl.json carried their Dutch. Two dashboard captions had no Dutch at
 * all. A lifecycle gesture the server could not save showed its English
 * `error`.
 *
 * The translate used here is the Dutch catalogue itself, so a label that
 * passes through it but has no entry comes back English and fails the test.
 *
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { refusalMessage } from '../../src/utils/caseLifecycleHelpers.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import {
	translateBannerCopy,
	translateLensLabels,
} from '../../src/utils/translateLensLabels.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const nl = readJson('l10n', 'nl.json').translations
const dutch = (text) => nl[text] ?? text

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

/**
 * The manifest as main.js hands it to the renderer, for one structure profile.
 *
 * @param {string} layout The profile file under src/.
 * @return {object} The built and translated manifest.
 */
function translated(layout) {
	const built = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', layout),
	)
	return translateBannerCopy(translateLensLabels(built, dutch), dutch)
}

function widgetOf(manifest, pageId, widgetId) {
	return manifest.pages
		.find((page) => page.id === pageId)
		.config.widgets.find((widget) => widget.id === widgetId)
}

describe.each(['menu-layout.simple.json', 'menu-layout.json'])(
	'the case page in %s',
	(layout) => {
		const manifest = translated(layout)

		it('labels its tabs in Dutch', () => {
			const panels = widgetOf(manifest, 'CaseDetail', 'case-panels').content
			const labels = panels.tabs.map((tab) => tab.label)

			expect(labels.length).toBeGreaterThan(3)
			for (const label of labels) {
				expect(Object.values(nl), label).toContain(label)
			}
			// A control: the catalogue check above would also pass on labels
			// that were never translated if they happened to be Dutch keys.
			expect(labels).not.toContain('Overview')
			expect(labels).not.toContain('Documents')
			expect(panels.ariaLabel).toBe('Zaakgegevens')
		})

		it('heads the panels under the tabs in Dutch', () => {
			const data = widgetOf(manifest, 'CaseDetail', 'case-data-panel').content
			const labels = data.sections.map((section) => section.label)

			expect(labels).toContain('Kerngegevens van de zaak')
			expect(labels).not.toContain('Core case data')
		})
	},
)

describe('the dashboard captions', () => {
	const manifest = translated('menu-layout.simple.json')

	it('says in Dutch how many tasks are due today', () => {
		expect(widgetOf(manifest, 'Dashboard', 'kpi-my-tasks').content.caption).toBe(
			'{tasksDueToday} vandaag af',
		)
	})

	it('says in Dutch what the SLA share counts', () => {
		expect(
			widgetOf(manifest, 'Dashboard', 'kpi-sla-compliance').content.caption,
		).toBe('haalden hun termijn, naar doorlooptijd')
	})
})

describe('a lifecycle gesture that could not be saved', () => {
	it('reads its code, not the English error beside it', () => {
		const body = {
			error: 'The case could not be changed.',
			code: 'change_failed',
		}

		expect(refusalMessage(body, dutch)).toBe(
			'De zaak kon niet worden gewijzigd.',
		)
	})
})
