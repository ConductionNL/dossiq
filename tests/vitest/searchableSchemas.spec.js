/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Consistency tests for the OR unified-search opt-in: the register definition
 * flags exactly the intended schemas `searchable: true`, and every flagged
 * schema has a matching deepLink entry whose urlTemplate points at a real
 * manifest page route. Read both JSON files from disk (not imported modules)
 * so the assertions cover the actual on-disk config the OR repair step reads.
 *
 * @spec openspec/changes/case-search-via-or-unified-search/specs/case-search-via-or-unified-search/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)
const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')

// 🔴 `caseTask` IS NOT HERE ANY MORE, AND THAT IS A LOSS, NOT A TIDY-UP.
// remove-casetask deleted the schema, so there is nothing left for unified
// search to index and nothing for a deepLink to resolve. An engine task has no
// unified-search provider at all today: the engine keeps its tasks in its own
// table, outside the object index this opt-in feeds. That gap belongs to
// OpenRegister, and `/tasks/:id` is asserted below so the page is ready the
// day a provider exists.
const EXPECTED_SEARCHABLE_SLUGS = ['case', 'objectionProceeding', 'beroep']

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

const FRAGMENT_DIR = path.resolve(__dirname, '../../lib/Settings/register.d')

/**
 * Case-owned records whose case field is not yet shown to hold a bare case
 * uuid (unified-search-opens-every-hit-in-dossiq, task 2.1).
 *
 * Their field is a plain string, and no writer in dossiq's own code shows what
 * goes in it (the rest are linked through `/apps/dossiq/cases/{<field>}` since
 * openregister#4555 hands the search formatter the object's own properties).
 * A read of a seeded or live object decides each one: a bare uuid gets a deep
 * link, anything else `searchable: false`. Until then they stay searchable and
 * open OpenRegister's own page. The list may only shrink.
 */
const PENDING_CASE_LINK = [
	'caseBerichtenboxMessage',
	'caseCustody',
	'caseFederatedActivity',
	'caseFederatedShare',
	'caseShare',
	'caseTakeover',
	'dispatch',
	'fieldInspection',
	'mailIntakeEntry',
	'mandateEscalation',
	'milestoneRecord',
	'portaalBericht',
	'toestemming',
]

/**
 * Every schema dossiq ships, merged the way the runtime merges them: the
 * base register, then each fragment in filename order, a re-declared schema
 * contributing its keys and properties.
 *
 * @return {object} Schemas by slug.
 */
function mergedSchemas() {
	const merged = {}
	const files = [REGISTER_PATH].concat(
		fs
			.readdirSync(FRAGMENT_DIR)
			.filter((name) => name.endsWith('.json'))
			.sort()
			.map((name) => path.join(FRAGMENT_DIR, name)),
	)
	for (const file of files) {
		const schemas = loadJson(file).components?.schemas ?? {}
		for (const [slug, schema] of Object.entries(schemas)) {
			const previous = merged[slug] ?? {}
			merged[slug] = {
				...previous,
				...schema,
				properties: {
					...(previous.properties ?? {}),
					...(schema.properties ?? {}),
				},
			}
		}
	}
	return merged
}

/**
 * The schemas a unified search hit would open nowhere useful: neither linked
 * to a dossiq page, nor opted out, nor knowingly pending (design D5).
 *
 * @param {object} schemas Schemas by slug.
 * @param {Array<object>} deepLinks The manifest's deep links.
 * @param {Array<string>} pending Slugs that wait on OpenRegister.
 * @return {Array<string>} The offending slugs.
 */
function unlinkedSchemas(schemas, deepLinks, pending) {
	const linked = new Set(deepLinks.map((entry) => entry.schemaSlug))
	return Object.keys(schemas).filter(
		(slug) =>
			!linked.has(slug)
			&& schemas[slug].searchable !== false
			&& !pending.includes(slug),
	)
}

describe('every hit opens a dossiq page or is not a hit (design D5)', () => {
	const schemas = mergedSchemas()
	const manifest = loadJson(MANIFEST_PATH)

	it('links or opts out every schema', () => {
		const unlinked = unlinkedSchemas(
			schemas,
			manifest.deepLinks,
			PENDING_CASE_LINK,
		)
		expect(
			unlinked,
			`link these to a page or set searchable: false: ${unlinked.join(', ')}`,
		).toEqual([])
	})

	it('names the schema a fixture with neither, in the message', () => {
		const fixture = { ...schemas, routingRuleCopy: { properties: {} } }
		const unlinked = unlinkedSchemas(
			fixture,
			manifest.deepLinks,
			PENDING_CASE_LINK,
		)
		expect(unlinked).toEqual(['routingRuleCopy'])
		expect(() =>
			expect(
				unlinked,
				`link these to a page or set searchable: false: ${unlinked.join(', ')}`,
			).toEqual([]),
		).toThrow(/routingRuleCopy/)
	})

	it('keeps the pending list honest: each entry is a case-owned schema still searchable', () => {
		for (const slug of PENDING_CASE_LINK) {
			expect(schemas[slug], `${slug} is a shipped schema`).toBeDefined()
			expect(
				schemas[slug].searchable,
				`${slug} left the list: remove it here`,
			).not.toBe(false)
			expect(
				manifest.deepLinks.map((entry) => entry.schemaSlug),
			).not.toContain(slug)
			const field = ['case', 'caseId', 'caseRef', 'parentCase'].find(
				(key) => key in schemas[slug].properties,
			)
			expect(field, `${slug} holds its case in a field`).toBeDefined()
		}
	})

	it('opens every deep link on a manifest route, filling only uuid or a property of its schema', () => {
		const pageRoutes = manifest.pages.map((page) => page.route)
		for (const entry of manifest.deepLinks) {
			expect(entry.registerSlug).toBe('dossiq')
			expect(
				schemas[entry.schemaSlug],
				`deep link on unknown schema ${entry.schemaSlug}`,
			).toBeDefined()
			expect(
				entry.displayName,
				`${entry.schemaSlug} names its hit`,
			).toBeTruthy()
			const route = entry.urlTemplate
				.replace(/^\/apps\/dossiq/, '')
				.replace(/\{[^}]+\}/g, ':id')
			expect(
				pageRoutes,
				`${entry.urlTemplate} is not a manifest route`,
			).toContain(route)
			for (const [, field] of entry.urlTemplate.matchAll(/\{([^}]+)\}/g)) {
				if (field !== 'uuid') {
					expect(
						schemas[entry.schemaSlug].properties,
						`${entry.schemaSlug} has no ${field}`,
					).toHaveProperty(field)
				}
			}
		}
	})

	it('keeps the explicit opt-in on case, bezwaar and beroep', () => {
		const register = loadJson(REGISTER_PATH)
		for (const slug of EXPECTED_SEARCHABLE_SLUGS) {
			expect(register.components.schemas[slug].searchable).toBe(true)
		}
	})

	it('keeps the published routes of the first deep links, and the task page', () => {
		const pageRoutes = manifest.pages.map((page) => page.route)
		const deepLinksBySlug = Object.fromEntries(
			manifest.deepLinks.map((entry) => [entry.schemaSlug, entry]),
		)

		// The KEY is the schema slug and moves with it; the URL is a published
		// ROUTE and deliberately does not: a route resolves at request time,
		// so breaking one fails silently.
		expect(deepLinksBySlug.case.urlTemplate).toBe('/apps/dossiq/cases/{uuid}')
		expect(deepLinksBySlug.objectionProceeding.urlTemplate).toBe(
			'/apps/dossiq/bezwaren/{uuid}',
		)
		expect(deepLinksBySlug.beroep.urlTemplate).toBe(
			'/apps/dossiq/beroepen/{uuid}',
		)

		// The task page survived the schema. Its route is what every
		// notification and bookmark holds.
		expect(
			pageRoutes,
			'the task page route must survive the schema it used to bind',
		).toContain('/tasks/:id')
		expect(
			deepLinksBySlug.caseTask,
			'a deepLink on a deleted schema resolves nothing and must not be re-added',
		).toBeUndefined()
	})
})

describe('version-gated re-import', () => {
	it('advances the register info.version past 0.11.0', () => {
		const register = loadJson(REGISTER_PATH)

		expect(register.info.version).not.toBe('0.11.0')
	})
})
