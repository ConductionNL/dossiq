// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The built-in dashboard widget catalog must be registered at bootstrap.
 *
 * The defect this pins down: nc-vue's `stat` / `object-table` / `countdown`
 * widgets register themselves when `registerDashboardWidgets.js` RUNS, and
 * webpack tree-shakes the barrel import that used to run it (the package's
 * `sideEffects` names only CSS). The only importer left was the lazy detail
 * page chunk, so a fresh load of `/apps/dossiq/` rendered the five KPI tiles
 * as "Widget not available" until a detail page had been visited once.
 *
 * `src/main.js` mounts the app on import and cannot be executed under
 * vitest, so this reads the source: the fix is one explicit call, and
 * removing it is exactly the regression to catch. The second test proves
 * the call is not a no-op against the installed package version.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const MAIN = path.resolve(__dirname, '../../src/main.js')

describe('dashboard widget catalog bootstrap', () => {
	const source = readFileSync(MAIN, 'utf8')

	it('imports registerBuiltinDashboardWidgets from @conduction/nextcloud-vue', () => {
		const importBlock = source.match(
			/import\s*\{([^}]*)\}\s*from\s*'@conduction\/nextcloud-vue'/,
		)
		expect(importBlock, 'nc-vue named import block').not.toBeNull()
		expect(importBlock[1]).toMatch(/\bregisterBuiltinDashboardWidgets\b/)
	})

	it('calls it before the app is mounted', () => {
		const call = source.indexOf('registerBuiltinDashboardWidgets()')
		const mount = source.indexOf('app.mount(')
		expect(call, 'the catalog registration call').toBeGreaterThan(-1)
		expect(mount, 'the mount call').toBeGreaterThan(-1)
		expect(call).toBeLessThan(mount)
	})

	it('names a function the installed nc-vue actually exports', () => {
		// The alias in vitest.config.js points the bare specifier at a stub,
		// so resolve the real package's barrel by path.
		const pkgDir = path.dirname(
			require.resolve('@conduction/nextcloud-vue/package.json'),
		)
		const barrel = readFileSync(path.join(pkgDir, 'dist/esm/index.js'), 'utf8')
		expect(barrel).toMatch(/export \{ registerBuiltinDashboardWidgets \}/)
	})
})
