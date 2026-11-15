/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * r30 DqBeheerGeavanceerd: Tenant-onboarding's field and Refresh stack at the
 * content edge, "Wat dossiq meelevert" picks with a settings field and no
 * second intro, and a prerequisite's "Aanwezig" keeps its green.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8')

describe('the advanced settings rows', () => {
	it('frames tenant onboarding as a section, field above Refresh', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'TenantOnboardingTab.vue')
		expect(tab).toMatch(/\.tenant-onboarding \{\s*padding: 0;\s*\}/)
		expect(tab).toMatch(/\.tenant-onboarding__controls \{[^}]*flex-direction: column;/)
	})

	it('draws the shipped configuration picker as a settings field without a second intro', () => {
		const view = read('src', 'views', 'settings', 'ShippedConfiguration.vue')
		expect(view).not.toContain('shipped-configuration__intro')
		expect(view).toMatch(/\.shipped-configuration__picker \{[^}]*max-width: 560px;/)
		expect(view).toMatch(/<NcSelect[^>]*:inputLabel="t\('dossiq', 'Show'\)"/)
		expect(view).not.toContain('<select')
	})

	it('draws the present badge as the success tag, not the neutral one', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'PrerequisitesTab.vue')
		expect(tab).toContain("'prerequisites__mark cn-status-badge cn-status-badge--success'")
		expect(tab).not.toContain('cn-status-badge--default prerequisites__mark--present')
	})
})
