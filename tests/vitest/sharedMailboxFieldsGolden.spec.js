/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the shared mailbox fields: every field, its order and each
 * translated text with the app it was translated for.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

import { describe, expect, it } from 'vitest'
import { sharedMailboxFields } from '../../src/views/settings/adminFields.js'

describe('sharedMailboxFields golden', () => {
	it('builds the same fields in the same order', async () => {
		const calls = []
		const t = (app, text, vars) => {
			calls.push([app, text, vars ?? null])
			return `${app}|${text}`
		}
		const fields = sharedMailboxFields(t)
		await expect(JSON.stringify({ fields, calls }, null, 2) + '\n').toMatchFileSnapshot('./golden/shared-mailbox-fields.json')
	})
})
