/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Export dossier header action, and the route it must land on.
 *
 * `registryOrphans.spec.js` guards the registered MODALS, and it is blind to
 * this one: an `api-call` opens no modal, so an export action deleted from
 * the header would leave DossierZipExporter and ZipManifestBuilder built,
 * routed and unreachable, which is the exact state row 4.20 was found in.
 * Nothing asserted this action until this file.
 *
 * The URL is checked against `appinfo/routes.php` as well as declared,
 * because a header action whose path no route answers renders a button that
 * 404s, and a button that fails on click looks the same in a manifest as one
 * that works.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const routesSource = fs.readFileSync(
	path.join(ROOT, 'appinfo', 'routes.php'),
	'utf8',
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** The CaseDetail page as the manifest declares it. @return {object} The page. */
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

/** The Export dossier header action. @return {object|undefined} The action. */
function exportAction () {
  return (caseDetail().config.headerActions || []).find(
		(action) => action.id === 'export-dossier',
	)
}

/**
 * Whether `appinfo/routes.php` answers this app path with this verb.
 *
 * The manifest writes the path the browser calls
 * (`/apps/dossiq/api/...`); routes.php writes it without the app prefix and
 * with `{caseId}` where the action carries a token.
 *
 * @param {string} url The action URL.
 * @param {string} verb The HTTP verb.
 * @return {boolean} Whether a route declares it.
 */
function routeAnswers(url, verb) {
	const withoutApp = url.replace('/apps/dossiq', '')
	// Every `@token` segment is a route placeholder.
	const pattern = withoutApp
		.split('/')
		.map((segment) => (segment.startsWith('@') ? '{[a-zA-Z]+}' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
		.join('/')
	const line = new RegExp(
		`'url'\\s*=>\\s*'${pattern}'[^\\]]*'verb'\\s*=>\\s*'${verb}'`,
	)
	return line.test(routesSource)
}

describe('CaseDetail — the case file leaves as one download (REQ-ZAK-022)', () => {
	it('offers Export dossier in the page header', () => {
		expect(
			exportAction(),
			'No header action on CaseDetail carries id `export-dossier`. '
				+ 'DossierZipExporter, ZipManifestBuilder and the zip route stay '
				+ 'built and routed when this goes, and no user can ask for a zip: '
				+ 'that is the state row 4.20 was found in. Put the action back, or '
				+ 'retire the exporter with it and say so in the PR.',
		).toBeDefined()
	})

	it('posts to a zip route that appinfo/routes.php actually answers', () => {
		const action = exportAction()
		expect(action.type).toBe('api-call')
		expect(action.method).toBe('POST')
		expect(action.url).toBe('/apps/dossiq/api/cases/@objectId/dossier/zip')
		expect(
			routeAnswers(action.url, action.method),
			`No route in appinfo/routes.php answers ${action.method} `
				+ `${action.url}. The button renders and 404s on click.`,
		).toBe(true)
	})

	it('asks for the response as a file rather than a toast', () => {
		// Without `download: true` the dispatcher treats the zip as a JSON
		// answer and shows a success message over bytes nobody receives.
		const action = exportAction()
		expect(action.download).toBe(true)
		expect(action.filename).toBe('dossier.zip')
	})

	it('names an icon src/icons.js registers', () => {
		// An unregistered name renders no icon at all, not a fallback glyph.
		const action = exportAction()
		expect(iconsSource).toContain(`\t${action.icon},`)
	})

	it('is offered on a closed case too', () => {
		// The archive export of a CLOSED case is half of what the action is
		// for, so a `visibleWhen` narrowing it to open cases would undo the
		// row it closes.
		expect(exportAction().visibleWhen).toBeUndefined()
	})
})
