// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Log contact names the portal channel after the portal, and says "Web form"
 * when no portal name is set.
 *
 * @spec openspec/specs/portal-channel-label/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

let pageConfig = {}
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (key === 'config' ? pageConfig : fallback) }))

const { default: LogContactDialog } = await import('../../src/dialogs/LogContactDialog.vue')
const portalOption = () => LogContactDialog.computed.channels.call({}).find((option) => option.value === 'webformulier')

describe('the portal channel in Log contact', () => {
	it('is named after the portal', () => {
		pageConfig = { portalName: 'Mijn Zuiddrecht' }
		expect(portalOption().label).toBe('Mijn Zuiddrecht')
	})

	it('says Web form without a portal name', () => {
		pageConfig = {}
		expect(portalOption().label).toBe('Web form')
	})
})
