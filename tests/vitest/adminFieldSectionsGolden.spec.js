/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The general, consultation, store and digest field lists, pinned whole:
 * every field, in order, with every property, and every text the translate
 * was asked for (with its variables).
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	consultationFields,
	houseStyleFields,
	storeRegistryFields,
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
	consultationFields,
	houseStyleFields,
	storeRegistryFields,
	textsAndAccessFields,
	workDigestFields,
}

describe('The general admin field lists', () => {
	for (const [name, build] of Object.entries(lists)) {
		it(`builds ${name} exactly as pinned`, () => {
			expect(build(t)).toMatchSnapshot()
		})
	}
})
