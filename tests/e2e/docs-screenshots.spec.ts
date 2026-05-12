/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — procest.
 *
 * This spec is *not* a regression test — it drives the Procest UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NC_BASE_URL=http://localhost:8080 \
 *     NC_ADMIN_USER=admin NC_ADMIN_PASS=admin \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default `npm run test:e2e` run via the
 * `docs-capture` project flag in `playwright.config.ts` so PR
 * pipelines don't reshoot screenshots on every push.
 *
 * Selector convention: capture-relevant surfaces in the app source
 * carry `data-testid="<scope>-<element>"` attributes. Add a story by
 * appending a new `test(...)` block — see `journeydoc-add-story`.
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

// Capture flows are independent — each test re-navigates from
// `/apps/procest/` so a selector miss on one doesn't cascade.
// Selector misses are the expected first-run failure mode (UI markup
// drifts faster than docs); failures land per-test in `test-results/`
// rather than killing the suite. Switch to `mode: 'serial'` if you
// actually need state continuity.
test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await page.goto('/apps/procest/')
})

// ---------------------------------------------------------------------------
// USER TRACK
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first-launch', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/01-first-launch.md
	})

	test('U2 my-work', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/02-my-work.md
	})

	test('U3 view-case', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/03-view-case.md
	})

	test('U4 advance-case', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/04-advance-case.md
	})

	test('U5 record-decision', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/05-record-decision.md
	})

	test('U6 track-deadlines', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/06-track-deadlines.md
	})

	test('U7 handle-objection', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/07-handle-objection.md
	})

	test('U8 inspection-checklist', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/user/08-inspection-checklist.md
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('A1 configure-case-types', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/admin/01-configure-case-types.md
	})

	test('A2 automatic-actions', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/admin/02-automatic-actions.md
	})

	test('A3 admin-settings', async ({ page }) => {
		// TODO: see /journeydoc-add-story — drive docs/tutorials/admin/03-admin-settings.md
	})
})
