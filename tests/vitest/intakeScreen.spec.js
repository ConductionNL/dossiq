/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The intake screen follows the case type.
 *
 * 🔑 A CASE TYPE NAMING A PAGE THAT IS NOT THERE MUST NOT ROUTE THE HANDLER
 * NOWHERE. A manifest changes independently of the case types configured
 * against it, so a page that was renamed or removed would otherwise open a
 * blank screen with nothing saying why.
 */

import { describe, expect, it } from 'vitest'
import { intakeScreenFor } from '../../src/utils/intakeScreen.js'

const pages = [
	{ id: 'case-intake' },
	{ id: 'vergunning-intake' },
	{ id: 'bezwaar-intake' },
]

describe('intakeScreenFor', () => {
	it('opens the screen the case type names', () => {
		expect(intakeScreenFor({ handling: { intakeScreen: 'vergunning-intake' } }, pages))
			.toBe('vergunning-intake')
	})

	it('opens a different screen for a different case type', () => {
		expect(intakeScreenFor({ handling: { intakeScreen: 'bezwaar-intake' } }, pages))
			.toBe('bezwaar-intake')
	})

	it('opens the standard screen when the case type names none', () => {
		expect(intakeScreenFor({ handling: {} }, pages)).toBe('case-intake')
		expect(intakeScreenFor({}, pages)).toBe('case-intake')
		expect(intakeScreenFor(null, pages)).toBe('case-intake')
	})

	it('falls back rather than routing to a page the manifest does not have', () => {
		expect(intakeScreenFor({ handling: { intakeScreen: 'page-that-went-away' } }, pages))
			.toBe('case-intake')
	})

	it('falls back when the manifest pages could not be read at all', () => {
		expect(intakeScreenFor({ handling: { intakeScreen: 'vergunning-intake' } }, null))
			.toBe('case-intake')
	})
})
