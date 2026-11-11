/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin field lists, pinned whole: every field, in order, with every
 * property, and every text the translate was asked for (with its variables).
 *
 * A field list is configuration the settings sections render as they find
 * it, so a list built from smaller pieces must come out exactly the same.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	aiFields,
	consultationFields,
	contactCentreFields,
	houseStyleFields,
	textsAndAccessFields,
	workDigestFields,
} from '../../src/views/settings/adminFields.js'

/**
 * A translate that shows what it was asked: the app, the text and its variables.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The variables.
 * @return {string} The marked text.
 */
function t(app, text, vars) {
	return `[${app}] ${text}${vars ? ' ' + JSON.stringify(vars) : ''}`
}

const lists = {
	aiFields,
	consultationFields,
	contactCentreFields,
	houseStyleFields,
	textsAndAccessFields,
	workDigestFields,
}

describe('The admin field lists', () => {
	for (const [name, build] of Object.entries(lists)) {
		it(`builds ${name} exactly as pinned`, () => {
			expect(build(t)).toMatchSnapshot()
		})
	}

	it('shares one visibility condition between the AI fields that show while AI is on', () => {
		const fields = aiFields(t)
		const on = fields.find((field) => field.key === 'ai_model_type').visibleWhen

		expect(fields.find((field) => field.key === 'ai_model_url').visibleWhen).toBe(on)
		expect(fields.find((field) => field.key === 'test-connection').visibleWhen).toBe(on)
	})
})
