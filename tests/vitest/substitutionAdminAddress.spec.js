// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Substitution admin lives in one place: the Substitution tab of the admin
 * settings (Ruben, 20 October 2026). The app's own page and its menu entry are
 * gone, and a bookmark to /substitution-admin lands on that tab.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { routesFromManifest, substitutionAdminUrl } from '../../src/utils/manifestRoutes.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))

describe('substitution admin in one place', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('sends the old address to the Substitution tab of the admin settings', async () => {
		const assign = vi.fn()
		vi.stubGlobal('location', { ...window.location, assign })
		const { createMemoryHistory, createRouter } = await import('vue-router')
		const router = createRouter({ history: createMemoryHistory(), routes: routesFromManifest(manifest, {}) })
		await router.push('/substitution-admin')
		expect(assign).toHaveBeenCalledWith(substitutionAdminUrl())
		expect(substitutionAdminUrl()).toMatch(/\/settings\/admin\/dossiq\?tab=substitution$/)
		vi.unstubAllGlobals()
	})

	it('has no page or menu entry of its own any more', () => {
		expect(manifest.pages.some((page) => page.route === '/substitution-admin')).toBe(false)
		expect(JSON.stringify(manifest.menu)).not.toContain('SubstitutionAdmin')
		expect(read('src', 'registry.js')).not.toContain('SubstitutionAdminView')
	})

	it('keeps the admin settings tab that the address opens', () => {
		const adminRoot = read('src', 'views', 'settings', 'AdminRoot.vue')
		expect(adminRoot).toContain("{ id: 'substitution', label: this.t('dossiq', 'Substitution') }")
		expect(adminRoot).toContain('<SubstitutionAdmin />')
	})
})
