// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every feature carries its category in the overlay, the source
 * docs/features.json is generated from.
 *
 * The dashboard groups the Features tab by `category`. It lived only in the
 * generated file, so the pre-commit regeneration dropped it on every spec
 * commit and DashboardControllerContractTest broke twice. With the category
 * in the overlay, the shared generator (ConductionNL/.github
 * scripts/extract-features.py) writes it on every run.
 *
 * @spec openspec/specs/features-overlay-category/spec.md#requirement-the-overlay-carries-each-features-category
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const overlay = JSON.parse(fs.readFileSync(path.join(ROOT, 'openspec/features.overlay.json'), 'utf8'))
const features = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/features.json'), 'utf8'))

describe('features overlay category', () => {
	it('gives every overlay entry a category', () => {
		expect(overlay.filter((entry) => typeof entry.category !== 'string' || entry.category === '').map((entry) => entry.slug)).toEqual([])
	})

	it('matches the category docs/features.json holds for the same feature', () => {
		const bySlug = Object.fromEntries(features.map((entry) => [entry.slug, entry.category]))
		expect(overlay.filter((entry) => bySlug[entry.slug] !== entry.category).map((entry) => entry.slug)).toEqual([])
	})
})
