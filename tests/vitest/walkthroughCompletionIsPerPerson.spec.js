// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Tour completion is stored per person, not once for the whole app.
 *
 * The shared walkthrough runner in @conduction/nextcloud-vue reads and writes
 * the completion key through the app's per-user preferences endpoint. A key
 * that moved to app config would make the first person to finish the tour the
 * last person to be offered it.
 *
 * This lived in PHPUnit (WalkthroughCompletionTest::testCompletionIsPerPerson)
 * and could only fail there: CI installs no node packages in the PHPUnit
 * cells, so the library file read back empty. The manifest half of that check
 * (the completion key's name) stays in PHPUnit; the library half is here,
 * where node_modules exists.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const COMPOSABLE = fileURLToPath(
	new URL(
		'../../node_modules/@conduction/nextcloud-vue/src/composables/useWalkthrough.js',
		import.meta.url,
	),
)

describe('walkthrough completion', () => {
	it('resolves the completion key to a per-user preference', () => {
		const source = readFileSync(COMPOSABLE, 'utf8')

		// A missing file must fail loudly, not pass as an empty string.
		expect(source.length).toBeGreaterThan(0)
		expect(source).toContain(
			"'/apps/' + appId + '/api/preferences/' + configKey",
		)
	})
})
