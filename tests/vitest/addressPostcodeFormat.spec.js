/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContact "Woonadres" and the contact card, DqOrganisatie "KvK en adres":
 * a postcode is stored as the registers keep it ("2999AA") and written as
 * people read it ("2999 AA"), through the facts `postcode` format.
 *
 * @spec openspec/changes/address-postcode-with-space/specs/contact-address-display/spec.md#requirement-an-address-writes-its-postcode-with-a-space
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8')

describe('the postcode in an address', () => {
	it('is read through the postcode format wherever an address is written', () => {
		for (const key of ['residence.postcode', 'address.postcode']) {
			const bare = manifest.match(new RegExp(`^\\s*"${key.replace('.', '\\.')}",?$`, 'gm')) || []
			expect(bare, key).toHaveLength(0)
			const formatted = manifest.match(new RegExp(`\\{ "path": "${key.replace('.', '\\.')}", "format": "postcode" \\}`, 'g')) || []
			expect(formatted.length, key).toBe(2)
		}
	})
})
