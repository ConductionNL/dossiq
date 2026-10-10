// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the round-4 cloud check found on the admin settings page, held as
 * contracts over the source that draws it.
 *
 * - Headings were in Title Case ("Case Type Management").
 * - Four sections repeated their own name as an inner heading.
 * - Status badges took `--color-success` and `--color-error` as INK, or put
 *   white ink on them as FILL. Since Nextcloud 32 those variables are light
 *   fills, so "present", "missing", "Enabled" and "Active" were near-invisible.
 *   The `-text` partner of each fill is the ink that meets WCAG AA on it.
 * - Tenant onboarding put its empty-state text in a slot NcEmptyContent does
 *   not render, so the section showed a bare icon.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const SETTINGS = path.join(ROOT, 'src/views/settings')
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8'),
).translations

/**
 * Every .vue and .css file under the settings views.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} Absolute paths.
 */
function files(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return files(full)
		}
		return /\.(vue|css)$/.test(entry.name) ? [full] : []
	})
}

const read = (file) => fs.readFileSync(file, 'utf8')
const adminRoot = read(path.join(SETTINGS, 'AdminRoot.vue'))

/** Words that keep their capital inside a sentence. */
const PROPER = new Set([
	'AI',
	'API',
	'AWB',
	'Awb',
	'DMN',
	'Dossiq',
	'KCC',
	'Nextcloud',
	'OpenRegister',
	'PHP',
	'StUF',
	'VTH',
	'Woo',
	'ZGW',
	'ZKN',
])

/**
 * The words of a heading that break sentence case.
 *
 * @param {string} heading The heading.
 * @return {Array<string>} The offending words.
 */
function titleCased(heading) {
	return heading
		.split(/\s+/)
		.slice(1)
		.filter((word) => {
			const bare = word.replace(/^[^A-Za-z]+|[^A-Za-z]+$/g, '')
			if (bare === '' || !/^[A-Z]/.test(bare)) {
				return false
			}
			// An acronym, or a word that starts with one (KCC-werkplek, StUF-ZKN).
			const head = bare.split('-')[0]
			if (PROPER.has(head) || /^[A-Z]{2,}$/.test(head)) {
				return false
			}
			return true
		})
}

/** Every heading string the admin page draws. */
const headings = files(SETTINGS).flatMap((file) => {
	const source = read(file)
	const found = [
		...source.matchAll(/<h[2-5][^>]*>\s*\{\{\s*t\('dossiq', '([^']+)'\)/g),
		...source.matchAll(
			/<CnSettingsSection[\s\S]*?:name="t\('dossiq', '([^']+)'\)"/g,
		),
		...source.matchAll(/<CnIndexPage[\s\S]*?:title="t\('dossiq', '([^']+)'\)"/g),
		...source.matchAll(
			/<CnAdminSettingsShell[\s\S]*?:title="t\('dossiq', '([^']+)'\)"/g,
		),
	].map((m) => m[1])
	return found.map((heading) => ({
		file: path.relative(ROOT, file),
		heading,
	}))
})

describe('admin settings headings', () => {
	it('finds the section names it checks', () => {
		// The control: a regex that matched nothing would pass every heading.
		expect(headings.length).toBeGreaterThan(30)
		expect(headings.map((h) => h.heading)).toContain('Search index')
		// The page title: the library's default is "{app} Settings".
		expect(headings.map((h) => h.heading)).toContain('Dossiq settings')
	})

	it.each(headings.map((h) => [h.file, h.heading]))(
		'%s: "%s" is in sentence case, with a Dutch entry',
		(file, heading) => {
			expect(titleCased(heading)).toEqual([])
			expect(nl[heading], `nl entry for "${heading}"`).toBeTruthy()
			expect(titleCased(nl[heading] ?? '')).toEqual([])
		},
	)
})

describe('a section does not repeat its own name', () => {
	const sections = [
		...adminRoot.matchAll(
			/:name="t\('dossiq', '([^']+)'\)"[\s\S]*?<(\w+)(?=[\s/>])[^>]*\/?>\s*<\/CnSettingsSection>/g,
		),
	]

	it('reads the sections and the tab each one renders', () => {
		expect(sections.length).toBeGreaterThan(15)
	})

	it.each(sections.map((m) => [m[1], m[2]]))(
		'"%s" (%s) carries no inner heading with its name',
		(name, component) => {
			const candidates = files(SETTINGS).filter(
				(file) => path.basename(file, '.vue') === component,
			)
			for (const file of candidates) {
				const inner = [
					...read(file).matchAll(
						/<h[1-6][^>]*>\s*\{\{\s*t\('dossiq', '([^']+)'\)/g,
					),
				].map((m) => m[1].toLowerCase())
				const own = name.toLowerCase()
				const stem = own.split(':')[0].trim()
				expect(inner, file).not.toContain(own)
				expect(inner, file).not.toContain(stem)
			}
		},
	)

	it('draws the shared mailbox section once', () => {
		expect(adminRoot.match(/'Case email: shared mailbox'/g) ?? []).toHaveLength(
			1,
		)
		expect(fs.existsSync(path.join(ROOT, 'src/emailSettings.js'))).toBe(false)
	})
})

describe('status colours', () => {
	const offences = files(SETTINGS).flatMap((file) => {
		const source = read(file)
		const style = file.endsWith('.css')
			? source
			: source.slice(source.indexOf('<style'))
		const found = []
		for (const m of style.matchAll(
			/(^|[\s;{])color:\s*var\(--color-(success|error|warning)\)\s*;/g,
		)) {
			found.push(`${path.relative(ROOT, file)}: ${m[0].trim()} used as ink`)
		}
		for (const block of style.matchAll(/\{([^{}]*)\}/g)) {
			const body = block[1]
			if (
				/background(?:-color)?:\s*var\(--color-(success|error|warning)\)/.test(
					body,
				)
				&& /(^|[\s;])color:\s*(white|#fff\b|#ffffff|var\(--color-main-background\)|var\(--color-primary-element-text\))/.test(
					body,
				)
			) {
				found.push(
					`${path.relative(ROOT, file)}: light ink on a status fill`,
				)
			}
		}
		return found
	})

	it('pairs every status fill with its -text ink', () => {
		expect(offences).toEqual([])
	})
})

describe('tenant onboarding without a tenant', () => {
	const tab = read(path.join(SETTINGS, 'tabs/TenantOnboardingTab.vue'))

	it('passes its empty-state text as name, which NcEmptyContent renders', () => {
		const empty = tab.match(/<NcEmptyContent[\s\S]*?<\/NcEmptyContent>/)[0]

		expect(empty).toContain(
			":name=\"t('dossiq', 'Select a tenant to view onboarding progress.')\"",
		)
		expect(empty).not.toContain('#default')
	})
})
