/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every record import a shipped case type declares carries the keys the
 * caseType schema requires; a missing one makes the register load refuse the
 * whole case type (seen on klacht-behandeling: "required property (stamp)").
 *
 * @spec openspec/changes/case-record-import/specs/case-record-import/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const SETTINGS = path.resolve(__dirname, '../../lib/Settings')
const register = JSON.parse(fs.readFileSync(path.join(SETTINGS, 'dossiq_register.json'), 'utf8'))
const required = register.components.schemas.caseType.properties.recordImports.items.required

function jsonFiles (dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
	const full = path.join(dir, entry.name)
	if (entry.isDirectory()) {
		return entry.name === 'example-sets' ? [] : jsonFiles(full)
	}
	return entry.name.endsWith('.json') ? [full] : []
})
}

function imports (node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => imports(item, found))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'recordImports' && Array.isArray(value)) {
				found.push(...value.filter((entry) => entry && typeof entry === 'object' && 'sourceSchema' in entry))
			} else {
				imports(value, found)
			}
		}
	}
	return found
}

describe('the shipped record imports', () => {
	it('declare every key the caseType schema requires', () => {
		const missing = []
		for (const file of jsonFiles(SETTINGS)) {
			let parsed
			try {
				parsed = JSON.parse(fs.readFileSync(file, 'utf8'))
			} catch {
				continue
			}
			for (const entry of imports(parsed)) {
				const absent = required.filter((key) => !(key in entry))
				if (absent.length > 0) {
					missing.push(`${path.relative(SETTINGS, file)} ${entry.key}: ${absent.join(', ')}`)
				}
			}
		}
		expect(missing).toEqual([])
	})
})
