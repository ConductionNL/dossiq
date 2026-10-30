/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqBeheerGeavanceerd "Tenant-onboarding": Vernieuwen stands 16px under
 * the tenant field. The board look gives a labelled select a -4px bottom
 * margin and the field a 2px ring, so the stack's gap is 16.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/tabs/TenantOnboardingTab.vue'), 'utf8')

describe('the tenant onboarding controls (DqBeheerGeavanceerd)', () => {
	it('stacks the tenant field and Refresh 16px apart', () => {
		const at = sfc.indexOf('.tenant-onboarding__controls {')
		const body = sfc.slice(at, sfc.indexOf('}', at))
		expect(body).toContain('gap: 16px;')
	})
})
