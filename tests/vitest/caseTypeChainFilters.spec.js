/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case carries the identifier its case type's version chain shares, so a
 * list, a quick filter or an action that means "every Woo request" reads one
 * identifier instead of the uuid of the first version: a case filed on a
 * later version is still a Woo request.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const register = JSON.parse(read('lib/Settings/dossiq_register.json'))
const caseSchema = register.components.schemas.case
const FIRST_WOO_VERSION = '3c0f5a00-0000-4000-a000-00000000a001'

describe('the case carries its case type identifier', () => {
	it('as a read-only property materialised from the case type', () => {
		expect(caseSchema.properties.caseTypeIdentifier).toMatchObject({ type: 'string', readOnly: true })
		expect(caseSchema.configuration['x-openregister-calculations'].caseTypeIdentifier).toMatchObject({
			type: 'string',
			materialise: true,
			expression: { prop: '@ref.caseType.identifier' },
		})
	})
})

describe('every Woo request, on any version', () => {
	it('is found by the identifier, not by the first version\'s uuid', () => {
		for (const file of ['src/manifest.json', 'src/menu-layout.simple.json']) {
			expect(read(file), file).not.toContain(FIRST_WOO_VERSION)
		}
		const manifest = JSON.parse(read('src/manifest.json'))
		const view = JSON.stringify(manifest).match(/"slug":"woo-requests"[^}]*"filters":\{[^}]*\}/)[0]
		expect(view).toContain('"caseTypeIdentifier":"woo-verzoek"')
		expect(read('src/manifest.json').match(/"field": "caseTypeIdentifier", "op": "eq", "value": "woo-verzoek"/g)).toHaveLength(6)
	})
})
