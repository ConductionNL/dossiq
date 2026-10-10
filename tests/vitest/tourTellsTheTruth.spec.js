// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The getting-started tour says only what the page shows, and the Woo refusal
 * grounds list labels what it shows.
 *
 * Each assertion is a sentence the round-4 cloud check read on the live app
 * that was not so:
 * - step 4 said "88 ship with the app"; the instance held 24 case types, and a
 *   number written into the manifest is wrong on every instance but one;
 * - step 7's title said "board" while its text said "Dashboard";
 * - step 2 said the list holds every case, open and closed, while the list
 *   itself says "open cases in your teams".
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/getting-started-tour/spec.md
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/woo-refusal-grounds/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const simple = fs.readFileSync(
	path.join(ROOT, 'src/menu-layout.simple.json'),
	'utf8',
)
const en = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n/en.json'), 'utf8'),
).translations
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8'),
).translations

const steps = Object.fromEntries(
	manifest.walkthrough.tours
		.find((tour) => tour.id === 'dossiq:getting-started')
		.steps.map((step) => [step.id, step]),
)

describe('the getting-started tour', () => {
	it('states no count of case types in the case types step', () => {
		const body = steps['see-case-types'].body

		expect(body).not.toMatch(/\d/)
		expect(nl[body] ?? '').not.toMatch(/\d/)
	})

	it('names the dashboard the same way in the last step title and text', () => {
		const { title, body } = steps.done

		expect(title.toLowerCase()).toContain('dashboard')
		expect(title.toLowerCase()).not.toMatch(/\bboard\b/)
		expect(body.toLowerCase()).toContain('dashboard')
		// The Dutch title and text agree with each other too.
		expect(nl[title].toLowerCase()).toContain('dashboard')
		expect(nl[body].toLowerCase()).toContain('dashboard')
	})

	it('describes the cases list the way the list describes itself', () => {
		const body = steps['go-cases'].body

		// The simple list's own header.
		expect(simple).toContain('open cases in your teams')
		expect(body).toContain('open cases in your teams')
		expect(body).not.toContain('open and closed')
	})

	it('has an English and a Dutch entry for every changed sentence', () => {
		for (const text of [
			steps['go-cases'].body,
			steps['see-case-types'].body,
			steps.done.title,
			steps.done.body,
		]) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toBe(text)
		}
	})
})

describe('the Woo refusal grounds list', () => {
	const page = manifest.pages.find((p) => p.id === 'WooRefusalGrounds')

	it('shows its title', () => {
		expect(page.title).toBe('Woo refusal grounds')
		expect(page.config.showTitle).toBe(true)
	})

	it('labels every column, in English and Dutch', () => {
		expect(page.config.columns.length).toBeGreaterThan(0)
		for (const column of page.config.columns) {
			expect(column.label, column.key).toBeTruthy()
			expect(nl[column.label], column.label).toBeTruthy()
		}
	})
})
