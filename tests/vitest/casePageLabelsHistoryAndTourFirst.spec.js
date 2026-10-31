/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three findings from the live review on :8098 (11 Oct).
 *
 * N-3: "Requester" and "Deadline" read English on a Dutch case page, although
 * both have their Dutch in nl.json. nextcloud-vue copies a field override's
 * label over the translated schema title as written, and CnCountdownWidget
 * draws its label as written.
 * N-6: the case page showed two "Geschiedenis" tabs: the simple profile names
 * the timeline tab History, and the sidebar named the audit trail History too.
 * N-7: a new user met the support note before the welcome tour.
 *
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 * @spec openspec/specs/first-load-onboarding/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { supportDialogForThisLoad } from '../../src/utils/onboardingOrder.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import {
	translateBannerCopy,
	translateFieldOverrideLabels,
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
 * The manifest as main.js hands it to the renderer, for one profile.
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
	return translateFieldOverrideLabels(
		translateBannerCopy(translateLensLabels(built, dutch), dutch),
		dutch,
	)
}

/**
 * Every `requester` override label anywhere in a manifest.
 *
 * @param {object} manifest The manifest.
 * @return {Array<string>} The labels.
 */
function requesterLabels(manifest) {
	const found = []
	const walk = (node) => {
		if (!node || typeof node !== 'object') {
			return
		}
		for (const [key, value] of Object.entries(node)) {
			if (
				(key === 'fieldOverrides' || key === 'overrides')
				&& typeof value?.requester?.label === 'string'
			) {
				found.push(value.requester.label)
			}
			walk(value)
		}
	}
	walk(manifest)
	return found
}

function caseDetail(manifest) {
	return manifest.pages.find((page) => page.id === 'CaseDetail')
}

describe('N-3: field labels and the term tile read Dutch', () => {
	for (const layout of ['menu-layout.simple.json', 'menu-layout.json']) {
		it(`the requester field is Aanvrager on every form and panel, Verzoeker on the case edit form (${layout})`, () => {
			const manifest = translated(layout)
			const edit = caseDetail(manifest).config.fieldOverrides
			const labels = requesterLabels(manifest)
			// Create forms on four pages, the nav's new-case action, the case
			// edit form and the Overview panel.
			expect(labels.length).toBeGreaterThanOrEqual(6)
			// The case edit form draws the requester as Verzoeker (DqZaakDialogen).
			expect(edit.requester.label).toBe('Verzoeker')
			labels.splice(labels.indexOf('Verzoeker'), 1)
			expect(new Set(labels)).toEqual(new Set(['Aanvrager']))
		})
	}

	it('the countdown tile label is translated in the full profile', () => {
		const tile = caseDetail(translated('menu-layout.json')).config.widgets.find(
			(widget) => widget.id === 'case-tile-deadline',
		)
		expect(tile.content.label).toBe('Termijn')
	})

	it('the simple side column heads the term card once, with the card title', () => {
		const tile = caseDetail(
			translated('menu-layout.simple.json'),
		).config.widgets.find((widget) => widget.id === 'case-tile-deadline')
		expect(dutch(tile.title)).toBe('Termijn')
		expect(tile.content.label).toBeUndefined()
		// The rest of the content is the manifest's, so the patch did not
		// quietly drop a threshold or the date.
		const base = caseDetail(
			readJson('src', 'manifest.json'),
		).config.widgets.find((widget) => widget.id === 'case-tile-deadline')
		const { label, ...rest } = base.content
		expect(label).toBe('Deadline')
		expect(tile.content).toMatchObject(rest)
		// And it draws the board's term card: days, the decide-by line, the
		// bar from the start date and the suspend act.
		expect(tile.content).toMatchObject({
			daysLabel: { one: '{count} day', other: '{count} days' },
			caption: 'Beslissen uiterlijk {date}',
			startField: 'startDate',
			action: { type: 'open-modal', target: 'CaseLifecycleMenuDialog', props: { act: 'suspend' } },
		})
	})
})

describe('N-6: the case page names every tab once', () => {
	for (const layout of ['menu-layout.simple.json', 'menu-layout.json']) {
		it(`tab strip and sidebar carry unique ids and unique Dutch labels (${layout})`, () => {
			const config = caseDetail(translated(layout)).config
			const strip = config.widgets.find(
				(widget) => widget.id === 'case-panels',
			).content.tabs
			const sidebar = config.sidebar.tabs
			const ids = [
				...strip.map((tab) => `strip:${Array.isArray(tab.widgetIds) ? tab.widgetIds[0] : tab.widgetId}`),
				...sidebar.map((tab) => `sidebar:${tab.id}`),
			]
			expect(new Set(ids).size).toBe(ids.length)
			const labels = [
				...strip.map((tab) => tab.label),
				...sidebar.map((tab) => dutch(tab.label)),
			]
			const twice = labels.filter((label, at) => labels.indexOf(label) !== at)
			expect(twice).toEqual([])
		})
	}

	it('the sidebar keeps the audit trail, as DqZaakHistorie draws it', () => {
		const audit = caseDetail(
			readJson('src', 'manifest.json'),
		).config.sidebar.tabs.find((tab) => tab.id === 'audit')
		expect(audit.label).toBe('Audit trail')
		expect(dutch(audit.label)).toBe('Audittrail')
	})
})

describe('N-7: the welcome tour comes before the support note', () => {
	it('holds the support note back while the tour is not yet seen', () => {
		expect(supportDialogForThisLoad(false)).toBe(false)
	})

	it('shows it once the tour is behind the user', () => {
		expect(supportDialogForThisLoad(true)).toBe(true)
	})

	it('keeps the library default when the page sent no answer', () => {
		expect(supportDialogForThisLoad(null)).toBe(true)
		expect(supportDialogForThisLoad(undefined)).toBe(true)
	})

	it('holds the note back on every load when an administrator switched it off', () => {
		expect(supportDialogForThisLoad(true, false)).toBe(false)
		expect(supportDialogForThisLoad(true, true)).toBe(true)
		expect(supportDialogForThisLoad(true, null)).toBe(true)
	})
})
