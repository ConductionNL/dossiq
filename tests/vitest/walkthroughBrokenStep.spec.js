/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A tour step that lost its surface is reported, not skipped.
 *
 * The runner filters steps by version and then renders them against the DOM. A
 * step whose target is gone simply never appears: no error, no console line,
 * no gap a reader would notice. That is how a tour quietly stops teaching half
 * the product, and it is why the report exists rather than a fix.
 *
 * This file checks the manifest half, which is what dossiq can see: a `page` or
 * `nav-item` target names an id the manifest declares. An `element` target
 * names a DOM test id, which no manifest resolves, so it is reported as
 * unverifiable instead of guessed at.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const manifest = readJson('src', 'manifest.json')
const tab = read('src', 'views', 'settings', 'tabs', 'FirstRunTab.vue')

/**
 * Every id a tour step could legitimately name: the pages and the menu.
 *
 * @return {string[]} The declared surface ids.
 */
function surfaces() {
	const ids = manifest.pages.map((page) => page.id)
	for (const entry of manifest.menu ?? []) {
		ids.push(entry.id)
		for (const child of entry.children ?? []) {
			ids.push(child.id)
		}
	}
	return ids.filter(Boolean)
}

/**
 * The same judgement FirstRunReadiness::brokenTourSteps() makes.
 *
 * @param {object[]} tours The tours to scan.
 * @param {string[]} known The surface ids the manifest declares.
 * @return {object[]} The broken and unverifiable steps.
 */
function scan(tours, known) {
	const out = []
	for (const tour of tours) {
		for (const step of tour.steps ?? []) {
			const kind = step.target?.kind ?? ''
			const ref = step.target?.ref ?? ''
			if (kind !== 'page' && kind !== 'nav-item') {
				out.push({ step: step.id, surface: ref, kind, state: 'unverifiable' })
				continue
			}
			if (!known.includes(ref)) {
				out.push({ step: step.id, surface: ref, kind, state: 'missing' })
			}
		}
	}
	return out
}

const tours = manifest.walkthrough.tours

describe('the shipped tour', () => {
	it('declares steps, so a green run means the scan ran', () => {
		expect(tours.length).toBeGreaterThan(0)
		expect(tours.flatMap((tour) => tour.steps).length).toBeGreaterThan(3)
	})

	it('names no page or nav entry this app does not have', () => {
		const missing = scan(tours, surfaces()).filter((step) => step.state === 'missing')
		expect(
			missing,
			`these steps name a surface that is gone: ${missing.map((s) => `${s.step} -> ${s.surface}`).join(', ')}`,
		).toEqual([])
	})

	it('gives every step its own version, so a later surface reaches a finisher', () => {
		for (const tour of tours) {
			for (const step of tour.steps) {
				expect(step.sinceVersion, `step ${step.id} carries no sinceVersion`).toBeTruthy()
			}
		}
	})
})

describe('the scan can actually see a broken step', () => {
	it('catches a step pointed at a page nobody ships, and names the surface', () => {
		// The same tours with one target rewritten. Without this the test above
		// passes on a healthy tree whether the scan works or not.
		const broken = JSON.parse(JSON.stringify(tours))
		broken[0].steps[1].target = { kind: 'nav-item', ref: 'APageNobodyShips' }

		const missing = scan(broken, surfaces()).filter((step) => step.state === 'missing')

		expect(missing).toHaveLength(1)
		expect(missing[0].surface).toBe('APageNobodyShips')
		expect(missing[0].step).toBe(tours[0].steps[1].id)
	})

	it('classifies an element target as unverifiable rather than broken', () => {
		const unverifiable = scan(tours, surfaces()).filter((step) => step.state === 'unverifiable')

		expect(unverifiable.length, 'no element target was classified, so the scan did not run').toBeGreaterThan(0)
		for (const step of unverifiable) {
			expect(step.kind).toBe('element')
		}
	})
})

describe('the report reaches a person', () => {
	it('shows the broken steps beside the readiness items', () => {
		expect(tab).toContain('data-testid="first-run-broken-steps"')
		expect(tab).toContain("step.state === 'missing'")
		expect(tab).toContain('These tour steps point at a screen that is gone')
	})
})
