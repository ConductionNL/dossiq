/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three admin pieces dossiq drew itself are nextcloud-vue components now:
 * what shipped (CnShippedConfigurationSection), the search index
 * (CnSearchIndexStatus) and a schema's rules (CnSchemaRulesWidget, widget
 * type `schema-rules`). Their behaviour is specified in nextcloud-vue; these
 * assertions pin what dossiq declares, so nothing it said is lost.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 * @spec openspec/specs/status-transition-engine/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const ROOT = path.resolve(__dirname, '../..')
const adminRoot = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
const registry = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

describe('what shipped with dossiq', () => {
	it('reads dossiq\'s shipped ledger for the four seeded schemas, and counts the register', () => {
		expect(adminRoot).toContain('<CnShippedConfigurationSection')
		expect(adminRoot).toContain('endpoint="/apps/dossiq/api/starter/shipped"')
		expect(adminRoot).toContain('countUrl="/apps/openregister/api/objects/dossiq/{schema}"')
		for (const slug of ['caseType', 'roleType', 'statusType', 'resultType']) {
			expect(adminRoot).toContain(`{ slug: '${slug}', label:`)
		}
	})
})

describe('the search index', () => {
	it('is OpenRegister\'s status, drawn by the library', () => {
		expect(adminRoot).toContain('<CnSearchIndexStatus v-if="storesReady" />')
		expect(adminRoot).toContain('id="section-search-index"')
	})
})

describe('the rules on the case', () => {
	const widget = manifest.pages.find((page) => page.id === 'CaseTypeDetail').config.widgets.find((w) => w.id === 'case-type-rules')

	it('is the schema rules widget over the case schema, its trial starting empty', () => {
		expect(widget.type).toBe('schema-rules')
		expect(widget.content).toMatchObject({ register: 'dossiq', schema: 'case', objectId: '' })
		expect(registry).not.toContain('CaseTypeRulesWidget')
	})

	it('keeps saying it about cases, in Dutch too', () => {
		for (const key of ['title', 'description', 'emptyText', 'objectLabel', 'objectPlaceholder', 'refusedText']) {
			expect(nl[widget.content[key]], key).toBeTruthy()
		}
	})
})
