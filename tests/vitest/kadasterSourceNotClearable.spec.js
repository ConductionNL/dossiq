/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqInstellingen "Kadaster (BRK) en WOZ instellen": a source select always
 * holds a source ("Geen: er gaan geen aanvragen naar buiten" is one), so it
 * draws no clear button (CnSettingsField clears only a field that is not required).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'
import { kadasterFields } from '../../src/views/settings/adminFields.js'

describe('the Kadaster and WOZ source selects', () => {
	it('are required, so they draw no clear button', () => {
		const sources = kadasterFields((app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? k)).filter((field) => field.type === 'select')
		expect(sources).toHaveLength(2)
		expect(sources.every((field) => field.required === true)).toBe(true)
	})
})
