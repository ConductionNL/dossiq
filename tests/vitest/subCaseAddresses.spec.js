// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sub-case pages are gone; their addresses land on the case page.
 *
 * A sub-case is a case, and its parent lists it on the Related tab. A
 * bookmark to the old list opens the parent on that tab, and a bookmark to
 * the old sub-case detail opens the sub-case itself.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { routesFromManifest } from '../../src/utils/manifestRoutes.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/**
 * A router over the shipped manifest.
 *
 * @return {Promise<object>} The router.
 */
async function router() {
	const { createMemoryHistory, createRouter } = await import('vue-router')
	return createRouter({
		history: createMemoryHistory(),
		routes: routesFromManifest(manifest, {}),
	})
}

describe('the retired sub-case addresses', () => {
	it('opens the parent case on its Related tab for the old list', async () => {
		const r = await router()
		await r.push('/cases/parent-1/deelzaken')
		expect(r.currentRoute.value.name).toBe('CaseDetail')
		expect(r.currentRoute.value.params.id).toBe('parent-1')
		expect(r.currentRoute.value.hash).toBe('#case-related-panel')
	})

	it('opens the sub-case itself for the old detail', async () => {
		const r = await router()
		await r.push('/cases/parent-1/deelzaken/child-1')
		expect(r.currentRoute.value.name).toBe('CaseDetail')
		expect(r.currentRoute.value.params.id).toBe('child-1')
		expect(r.currentRoute.value.hash).toBe('')
	})

	it('points the hash at a tab the case page carries, holding the sub-cases', () => {
		const detail = manifest.pages.find((p) => p.id === 'CaseDetail')
		const tabs = detail.config.widgets.find((w) => w.id === 'case-panels').content.tabs
		expect(tabs.map((tab) => tab.widgetId)).toContain('case-related-panel')
		const related = detail.config.widgets.find((w) => w.id === 'case-related-panel')
		expect(related.content.sections.map((sec) => sec.widget?.id)).toContain('case-sub-cases')
	})

	it('are no pages of their own any more', () => {
		const ids = manifest.pages.map((p) => p.id)
		expect(ids).not.toContain('DeelzaakList')
		expect(ids).not.toContain('DeelzaakDetail')
	})
})
