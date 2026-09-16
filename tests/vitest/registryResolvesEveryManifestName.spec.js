/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every name the manifests hand the renderer resolves in src/registry.js, with
 * a kind the renderer accepts.
 *
 * The registry is the ONLY lookup behind a manifest name, and both ways it can
 * miss are silent: a `type: "custom"` page whose component does not resolve
 * draws the renderer's "This page is empty" placeholder, and a handler that
 * does not resolve leaves a menu item that does nothing when clicked, with
 * nothing in the console — CnIndexPage warns only when the name matches
 * something UNCALLABLE, not when it matches nothing at all.
 *
 * The kinds are not interchangeable and the rules are the library's, not ours:
 *  - `component` / `sidebarComponent` go through `resolveCustomComponent(name,
 *    'page')`, so an entry of any other kind is SKIPPED even when it carries a
 *    perfectly good component;
 *  - a `slots.*` entry resolves with no required kind, but needs a `component`;
 *  - a `handler` needs a FUNCTION, on `.handler` / `.fn` or as the entry
 *    itself, because `resolveRegisteredHandler` reads it by shape.
 *
 * Asserted against the registry SOURCE rather than by importing it: registry.js
 * imports every page and tab this app mounts, so importing it here would mount
 * the component tree to read a map of strings.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

/**
 * The manifest and every backend-merged fragment beside it. A fragment names
 * components exactly as the manifest does (`MailIntakeLogView` and
 * `ExternalConsultationResponsePage` are only ever named from one), so a sweep
 * that read manifest.json alone would miss the two pages hardest to notice.
 *
 * @return {Array<{name: string, doc: object}>} The parsed documents.
 */
function manifests() {
	const dir = path.join(ROOT, 'src', 'manifest.d')
	const fragments = fs.existsSync(dir)
		? fs.readdirSync(dir).filter((f) => f.endsWith('.json') && !f.startsWith('_'))
		: []

	return [
		{ name: 'manifest.json', doc: JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8')) },
		...fragments.map((f) => ({
			name: `manifest.d/${f}`,
			doc: JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')),
		})),
	]
}

/**
 * The registry's entries as `{ key: body }`, where body is the source text
 * between this key and the next one.
 *
 * @return {Record<string, string>} Entry bodies by registry key.
 */
function registryEntries() {
	const body = registrySource.slice(registrySource.indexOf('const registry = {'))
	const lines = body.split('\n')
	const starts = []
	lines.forEach((line, i) => {
		const m = line.match(/^\t(?:'([^']+)'|([A-Za-z0-9_$]+)):\s*\{\s*$/)
		if (m) {
			starts.push({ key: m[1] || m[2], i })
		}
	})

	const out = {}
	starts.forEach(({ key, i }, n) => {
		const end = n + 1 < starts.length ? starts[n + 1].i : lines.length
		out[key] = lines.slice(i, end).join('\n')
	})
	return out
}

const ENTRIES = registryEntries()

/** The `handler` values that are library keywords rather than registry names. */
const KEYWORDS = new Set([
	'navigate', 'emit', 'none', 'open-modal', 'open-page', 'refresh',
	'export', 'api-call', 'handler', 'toggle', 'open-form', 'agent', 'object-op',
])

/**
 * Whether a `component`-ish key is one of the three the renderer resolves with
 * `requireKind: 'page'`, which is POSITIONAL and not a matter of the key's name:
 * a page's own `component`, its `sidebarComponent`, and a `slots.main` that is
 * standing in for an absent `component` (CnPageRenderer promotes it to the page
 * body). A `component` further down — a sidebar tab, a body widget — resolves
 * with no required kind at all, so demanding 'page' there would reject a
 * registration the library accepts.
 *
 * @param {Array<string>} at Path to the object carrying the key.
 * @param {string} key The key itself.
 * @return {boolean} True when the name must be a `kind: 'page'` entry.
 */
function needsPageKind(at, key) {
	if (key === 'sidebarComponent') {
		return true
	}
	return key === 'component' && /^pages\.\[\d+\]$/.test(at.join('.'))
}

/**
 * Every registry name the manifests hand the renderer, with where it came from
 * and what the renderer will demand of it.
 *
 * @return {Array<{doc: string, at: string, name: string, needs: string}>} The references.
 */
function references() {
	const found = []

	/**
	 * @param {*} node The current manifest node.
	 * @param {Array<string>} at Path to it, for the failure message.
	 * @param {string} doc Which manifest file this came from.
	 */
	function walk(node, at, doc) {
		if (Array.isArray(node)) {
			node.forEach((entry, i) => walk(entry, [...at, `[${i}]`], doc))
			return
		}
		if (!node || typeof node !== 'object') {
			return
		}

		for (const [key, value] of Object.entries(node)) {
			if (typeof value === 'string') {
				if (key === 'component' || key === 'sidebarComponent') {
					found.push({
						doc,
						at: [...at, key].join('.'),
						name: value,
						needs: needsPageKind(at, key) ? 'page' : 'component',
					})
				} else if (key === 'headerComponent' || key === 'actionsComponent' || key === 'cardComponent' || key === 'listComponent') {
					found.push({ doc, at: [...at, key].join('.'), name: value, needs: 'component' })
				} else if (key === 'handler' && !KEYWORDS.has(value)) {
					found.push({ doc, at: [...at, key].join('.'), name: value, needs: 'function' })
				} else if (key === 'createOverride') {
					found.push({ doc, at: [...at, key].join('.'), name: value, needs: 'function' })
				}
			}
			if (key === 'slots' && value && typeof value === 'object' && !Array.isArray(value)) {
				for (const [slot, name] of Object.entries(value)) {
					if (typeof name === 'string') {
						// `main` on a page that declares no `component` IS the page
						// body, resolved with requireKind 'page'; beside a
						// `component` it is an ordinary slot, like every other.
						const isBody = slot === 'main' && !node.component
						found.push({
							doc,
							at: [...at, 'slots', slot].join('.'),
							name,
							needs: isBody ? 'page' : 'component',
						})
					}
				}
				continue
			}
			walk(value, [...at, key], doc)
		}
	}

	for (const { name, doc } of manifests()) {
		walk(doc, [], name)
	}
	return found
}

const REFERENCES = references()

describe('the registry answers every name the manifests use', () => {
	it('finds references to sweep at all', () => {
		// A walker that silently stops matching would make every assertion
		// below pass over an empty list, which is the one way this file could
		// go green while guarding nothing.
		expect(REFERENCES.length).toBeGreaterThan(40)
		expect(Object.keys(ENTRIES).length).toBeGreaterThan(80)
	})

	it('registers every name', () => {
		const missing = REFERENCES.filter((r) => !ENTRIES[r.name])
			.map((r) => `${r.doc}: ${r.at} = ${r.name}`)
		expect(missing).toEqual([])
	})

	it("gives every page component kind 'page', which is the kind the renderer demands", () => {
		const wrong = REFERENCES
			.filter((r) => r.needs === 'page' && ENTRIES[r.name] && !/^\t\tkind: 'page',$/m.test(ENTRIES[r.name]))
			.map((r) => `${r.doc}: ${r.at} = ${r.name}`)
		expect(wrong).toEqual([])
	})

	it('gives every slot and header/actions component something to mount', () => {
		const wrong = REFERENCES
			.filter((r) => r.needs === 'component' && ENTRIES[r.name] && !/^\t\tcomponent: /m.test(ENTRIES[r.name]))
			.map((r) => `${r.doc}: ${r.at} = ${r.name}`)
		expect(wrong).toEqual([])
	})

	it('gives every named handler a function', () => {
		const wrong = REFERENCES
			.filter((r) => r.needs === 'function' && ENTRIES[r.name]
				&& !/^\t\t(?:handler|fn): /m.test(ENTRIES[r.name]))
			.map((r) => `${r.doc}: ${r.at} = ${r.name}`)
		expect(wrong).toEqual([])
	})

	it("declares no kind the library does not know, so nothing throws RegistryKindError", () => {
		// CnAppRoot throws on an unknown kind at mount, which stops the two
		// calls after it — the deprecation check and the menu-count hydration,
		// so the nav badges stay empty.
		const KNOWN = new Set([
			'widget', 'modal', 'page', 'form-field', 'cell-renderer',
			'header', 'actions', 'tab', 'section', 'handler', 'create-override',
		])
		const unknown = []
		for (const [key, body] of Object.entries(ENTRIES)) {
			const m = body.match(/^\t\tkind: '([a-z-]+)',$/m)
			if (m && !KNOWN.has(m[1])) {
				unknown.push(`${key} (kind: ${m[1]})`)
			}
		}
		expect(unknown).toEqual([])
	})

	it('gives every widget entry the grid metadata the validator requires', () => {
		// Missing metadata is five console.warns per entry at every mount.
		const REQUIRED = ['defaultSize', 'minSize', 'maxSize', 'allowedSlots', 'propsSchema']
		const bare = []
		for (const [key, body] of Object.entries(ENTRIES)) {
			if (!/^\t\tkind: 'widget',$/m.test(body)) {
				continue
			}
			const spreadsMeta = /\.\.\.[A-Z_]+_META,/.test(body)
			const inline = REQUIRED.every((f) => new RegExp(`^\\t\\t${f}: `, 'm').test(body))
			if (!spreadsMeta && !inline) {
				bare.push(key)
			}
		}
		expect(bare).toEqual([])
	})
})
