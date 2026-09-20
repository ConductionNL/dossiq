/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The work instruction for this kind of case, next to the case.
 *
 * dossiq builds no wiki. Collectives is one, its pages live in a Nextcloud
 * team, and that team is what makes visibility per role. So everything
 * asserted here is a DECLARATION and an ABSENCE:
 *
 *  - the case can link the leaf's pages, which means `collectives` and
 *    `xwiki` are in `case.linkedTypes`;
 *  - the case type points at its own work instruction, which means
 *    `knowledgeBasePage` is a declared property, or OpenRegister's magic
 *    mapper drops the value on the way in and the field reads as one nobody
 *    filled;
 *  - dossiq stores NO article text. A property holding a body would be a
 *    second copy of a page, which goes stale and, worse, is readable by
 *    somebody the collective's team excludes.
 *
 * The `requiredApp` half is asserted against the library's own descriptor
 * rather than restated here: if the leaf ever stopped declaring it, the tab
 * would render on an instance without Collectives and say nothing, and a
 * copy of the string in this file could not see that.
 *
 * @spec openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const manifest = readJson('src', 'manifest.json')
const register = readJson('lib', 'Settings', 'dossiq_register.json')
const fragment = readJson(
	'lib',
	'Settings',
	'register.d',
	'41-case-knowledge-base.json',
)

/**
 * One page of the shipped manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	const found = (manifest.pages || []).find((entry) => entry.id === id)
	expect(found, `the manifest has no page "${id}"`).toBeTruthy()
	return found
}

describe('a case links the pages that explain it', () => {
	it('carries both knowledge leaves in linkedTypes', () => {
		const linked = register.components.schemas.case.configuration.linkedTypes
		expect(linked).toContain('collectives')
		expect(linked).toContain('xwiki')
	})

	it('places the Knowledge tab as the leaf, not as a dossiq surface', () => {
		const detail = page('CaseDetail')
		const widget = (detail.config.widgets || []).find(
			(w) => w.id === 'case-knowledge-panel',
		)
		expect(widget, 'the case page has no Knowledge widget').toBeTruthy()
		expect(widget.type).toBe('case-sections')

		// The pages the case itself links are STILL the leaf and nothing
		// dossiq built. The section moved, the ownership did not.
		const sections = widget.content.sections || []
		const pages = sections.map((s) => s.widget).find((w) => w?.id === 'case-knowledge-pages')
		expect(
			pages,
			'the linked pages section is gone, so the case can no longer show the pages somebody linked to it',
		).toBeTruthy()
		expect(pages.type).toBe('integration')
		expect(pages.integrationId).toBe('collectives')

		const strip = (detail.config.widgets || []).find(
			(w) => w.id === 'case-panels',
		)
		const labels = (strip.content.tabs || []).map((t) => t.label)
		expect(labels).toContain('Knowledge')
		expect(
			(strip.content.tabs || []).find((t) => t.label === 'Knowledge').widgetId,
		).toBe('case-knowledge-panel')
	})

	it('leans on the leaf descriptor for requiredApp rather than restating it', async () => {
		const descriptor = readJson(
			'node_modules',
			'@conduction',
			'nextcloud-vue',
			'package.json',
		)
		expect(descriptor.name).toBe('@conduction/nextcloud-vue')
		const source = fs.readFileSync(
			path.join(
				ROOT,
				'node_modules/@conduction/nextcloud-vue/src/integrations/builtin/collectives.js',
			),
			'utf8',
		)
		expect(
			source,
			'the collectives leaf no longer declares requiredApp, so the Knowledge tab would render on an instance without the app and say nothing',
		).toContain("requiredApp: 'collectives'")
	})
})

describe('the case type points at its own work instruction', () => {
	it('declares knowledgeBasePage, or the value is dropped on the way in', () => {
		const prop =
			fragment.components.schemas.caseType.properties.knowledgeBasePage
		expect(prop, 'knowledgeBasePage is not declared on caseType').toBeTruthy()
		expect(prop.type).toBe('string')
		expect(
			prop.format,
			'a format on a property OpenRegister already stores is breaking for every row that does not match it',
		).toBeUndefined()
	})

	it('offers the field where a case type is authored', () => {
		const core = (page('CaseTypeDetail').config.widgets || []).find(
			(w) => w.id === 'case-type-core',
		)
		expect(core.content.include).toContain('knowledgeBasePage')
	})
})

describe('dossiq stores no article', () => {
	it('declares no property anywhere that holds article body text', () => {
		const documents = [register]
		const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
		fs.readdirSync(dir)
			.filter((f) => f.endsWith('.json'))
			.forEach((f) =>
				documents.push(readJson('lib', 'Settings', 'register.d', f)),
			)

		const offenders = []
		for (const doc of documents) {
			const schemas = (doc.components && doc.components.schemas) || {}
			for (const [schemaName, schema] of Object.entries(schemas)) {
				for (const name of Object.keys(schema.properties || {})) {
					if (
						/^(articleBody|knowledgeArticle|wikiContent|pageBody)$/.test(
							name,
						)
					) {
						offenders.push(`${schemaName}.${name}`)
					}
				}
			}
		}
		expect(
			offenders,
			"a dossiq schema holds article text, which is a second copy of a page that goes stale and that the collective's team cannot keep anybody out of",
		).toEqual([])
	})

	it('keeps the reference a page, not its text', () => {
		const prop =
			fragment.components.schemas.caseType.properties.knowledgeBasePage
		expect(prop.type).not.toBe('object')
		expect(prop.title).toBe('Work instruction')
	})
})
