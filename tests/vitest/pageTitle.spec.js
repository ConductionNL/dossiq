// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The browser tab names the dossiq page on screen.
 *
 * Before this, every dossiq tab read "Dossiq - Conduction Nextcloud" (the
 * title Nextcloud renders once, server side), whatever page was open. The
 * wiring test reads `src/main.js` itself, because a title helper with a full
 * suite and no call site changes nothing a person sees.
 *
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/page-titles/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import manifest from '../../src/manifest.json'
import { installPageTitles, pageTitleFor } from '../../src/utils/pageTitle.js'

const here = dirname(fileURLToPath(import.meta.url))
const BASE = 'Dossiq - Conduction Nextcloud'

describe('pageTitleFor', () => {
	it('puts the page name in front of the server title', () => {
		expect(pageTitleFor(manifest, { meta: { cnPageId: 'Cases' } }, BASE)).toBe(
			'Cases - Dossiq - Conduction Nextcloud',
		)
	})

	it('translates the page name', () => {
		const nl = { Cases: 'Zaken' }
		expect(
			pageTitleFor(
				manifest,
				{ meta: { cnPageId: 'Cases' } },
				BASE,
				(s) => nl[s] || s,
			),
		).toBe('Zaken - Dossiq - Conduction Nextcloud')
	})

	it('falls back to the route name when the record has no page id', () => {
		expect(pageTitleFor(manifest, { name: 'CaseDetail' }, BASE)).toBe(
			'Case - Dossiq - Conduction Nextcloud',
		)
	})

	it('keeps the server title for a route that names no page', () => {
		expect(pageTitleFor(manifest, { path: '/nowhere' }, BASE)).toBe(BASE)
		expect(pageTitleFor(manifest, { name: 'NoSuchPage' }, BASE)).toBe(BASE)
	})

	it('writes every page title in sentence case', () => {
		// A tab title is read in a list of tabs; "My Work" beside "Cases" reads
		// as a different kind of thing. Acronyms (LHS, AWB, BAC) stay capitals.
		const titleCase = manifest.pages
			.map((page) => page.title || '')
			.filter((title) =>
				title
					.split(/\s+/)
					.slice(1)
					.some((word) => /^[A-Z][a-z]/.test(word)),
			)
		expect(titleCase).toEqual([])
	})
})

describe('installPageTitles', () => {
	it('retitles the tab on every route change, without stacking names', async () => {
		const doc = { title: BASE }
		const router = createRouter({
			history: createMemoryHistory(),
			routes: [
				{
					path: '/',
					name: 'MyWorkHome',
					component: {},
					meta: { cnPageId: 'MyWorkHome' },
				},
				{
					path: '/cases',
					name: 'Cases',
					component: {},
					meta: { cnPageId: 'Cases' },
				},
				{
					path: '/cases/:id',
					name: 'CaseDetail',
					component: {},
					meta: { cnPageId: 'CaseDetail' },
				},
			],
		})
		installPageTitles(router, manifest, (s) => s, doc)

		await router.push('/cases')
		expect(doc.title).toBe('Cases - Dossiq - Conduction Nextcloud')

		await router.push('/cases/abc')
		expect(doc.title).toBe('Case - Dossiq - Conduction Nextcloud')

		await router.push('/')
		expect(doc.title).toBe('My work - Dossiq - Conduction Nextcloud')
	})

	it('is installed on the app router in main.js', () => {
		const source = readFileSync(resolve(here, '../../src/main.js'), 'utf8')
		expect(source).toMatch(
			/import \{ installPageTitles \} from '\.\/utils\/pageTitle\.js'/,
		)
		expect(source).toMatch(/installPageTitles\(\s*router,\s*builtManifest,/)
	})
})
