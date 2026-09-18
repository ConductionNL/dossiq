/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schemas that were registered with nowhere to read them.
 *
 * Each shipped its storage and never shipped a page, so an instance created the
 * table, filled it, and offered nobody a way to look. For the first two that is
 * a privacy problem before it is a usability one: a supplier user is a named
 * person with an email address and an eHerkenning level, a breach record says
 * how personal data escaped, and a data subject asking what is held about them
 * could not be answered. For the offline three it is the opposite finding: the
 * pages will be empty on every instance today, and an empty list somebody can
 * open is how you learn the offline story ends at the schema.
 *
 * Every assertion here guards something that fails SILENTLY: a page with no
 * menu entry is routable and unfindable, an unregistered icon renders no glyph
 * rather than a fallback, a page naming a schema the register does not declare
 * renders an empty list, and a credential in a column renders perfectly.
 *
 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** Every schema the register files declare, by slug. */
function declaredSchemas() {
	const files = [path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json')]
	const fragmentDir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(fragmentDir).sort()) {
		if (name.endsWith('.json')) files.push(path.join(fragmentDir, name))
	}

	const out = {}
	for (const file of files) {
		const data = JSON.parse(fs.readFileSync(file, 'utf8'))
		for (const [key, schema] of Object.entries(
			data?.components?.schemas ?? {},
		)) {
			out[schema?.slug || key] = schema
		}
	}
	return out
}

const schemas = declaredSchemas()

/**
 * One page by id.
 *
 * @param {string} id - The page id.
 * @return {object|undefined} The page.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

/**
 * One menu entry by id.
 *
 * @param {string} id - The menu entry id.
 * @return {object|undefined} The entry.
 */
function menuEntry(id) {
	return manifest.menu.find((entry) => entry.id === id)
}

/**
 * Whether an icon will actually render.
 *
 * @param {string} name - The PascalCase icon name.
 * @return {boolean} True when both the import and the export are present.
 */
function iconRenders(name) {
	return (
		iconsSource.includes(
			`import ${name} from 'vue-material-design-icons/${name}.vue'`,
		) && new RegExp(`^\\t${name},$`, 'm').test(iconsSource)
	)
}

describe.each([
	['SupplierUsers', 'SupplierUsersMenu', 'supplierUser'],
	['AvgIncidents', 'AvgIncidentsMenu', 'avgIncident'],
	['FieldEvidence', 'FieldEvidenceMenu', 'fieldEvidence'],
	['OfflineSyncQueue', 'OfflineSyncQueueMenu', 'syncQueue'],
	['OfflineSyncConflicts', 'OfflineSyncConflictsMenu', 'conflictRecord'],
])('%s', (pageId, menuId, slug) => {
	it('is a page over the schema the register declares', () => {
		const p = page(pageId)
		expect(p).toBeTruthy()
		expect(p.type).toBe('index')
		expect(p.config.register).toBe('dossiq')
		expect(p.config.schema).toBe(slug)
		// A page naming a schema nothing declares renders an empty list and
		// says nothing, which is indistinguishable from no records.
		expect(schemas[slug]).toBeTruthy()
	})

	it('is in the menu, so it can be found and not only routed to', () => {
		const entry = menuEntry(menuId)
		expect(entry).toBeTruthy()
		expect(entry.route).toBe(pageId)
		expect(entry.section).toBe('settings')
	})

	it('names an icon that renders', () => {
		expect(iconRenders(menuEntry(menuId).icon)).toBe(true)
	})

	it('is admin-only on the page and in the menu, not one or the other', () => {
		// The menu entry hides the link; the page permission refuses the
		// route. A page gated only in the menu is reachable by typing the URL.
		expect(page(pageId).permission).toBe('admin')
		expect(menuEntry(menuId).permission).toBe('admin')
	})

	it('shows only columns the schema actually has', () => {
		const declared = Object.keys(schemas[slug].properties ?? {})
		for (const column of page(pageId).config.columns) {
			expect(declared).toContain(column)
		}
	})
})

describe('what the supplier accounts page does not show', () => {
	it('keeps the activation token out of the columns', () => {
		// A one-time credential. It is on the record because activation needs
		// it, and a list is the wrong place: an admin screenshotting the page
		// would be handing out account access. The sidebar still has it for
		// anyone who genuinely needs it.
		expect(page('SupplierUsers').config.columns).not.toContain(
			'activationToken',
		)
		expect(schemas.supplierUser.properties.activationToken).toBeTruthy()
	})
})

describe('the breach register reads newest first', () => {
	it('sorts on the incident date descending', () => {
		// The 72-hour notification clock runs from the incident, so the row
		// that matters is the most recent one, not the first ever recorded.
		expect(page('AvgIncidents').config.defaultSort).toBe('-incidentDate')
	})
})

describe('what the field evidence page does not show', () => {
	it('keeps the content, the file link and the location out of the columns', () => {
		// `transcription` is what somebody said, `cloudUrl` is a direct link
		// to the file and `gpsLocation` is where a person stood. None belongs
		// in a list that is scanned rather than opened, which is why this
		// schema carries a sensitivityLevel at all. The record itself opens in
		// the sidebar for anyone who needs it.
		const columns = page('FieldEvidence').config.columns
		for (const hidden of ['transcription', 'cloudUrl', 'gpsLocation']) {
			expect(columns).not.toContain(hidden)
			// And the field still exists, so this test fails if the reason for
			// it quietly disappears.
			expect(schemas.fieldEvidence.properties[hidden]).toBeTruthy()
		}

		expect(columns).toContain('sensitivityLevel')
	})
})

describe('the sync surfaces show what somebody came to find out', () => {
	it('puts the last error on the queue, and not the payload', () => {
		// A queue that stopped draining is the only thing anybody opens this
		// page for. The payload is the whole object being replayed and would
		// print as an object in a cell.
		const columns = page('OfflineSyncQueue').config.columns
		expect(columns).toContain('lastError')
		expect(columns).toContain('attemptCount')
		expect(columns).not.toContain('payload')
	})

	it('keeps the two version snapshots off the conflict list', () => {
		// Comparing them is what the sidebar is for; a column holding one
		// would print an object.
		const columns = page('OfflineSyncConflicts').config.columns
		expect(columns).not.toContain('serverVersion')
		expect(columns).not.toContain('clientVersion')
		expect(columns).toContain('conflictType')
	})
})

describe('the settings menu keeps its entries in a stated order', () => {
	it('gives every settings entry a distinct order', () => {
		// Two entries on the same order are placed by whatever the sort is
		// stable about, which is nothing anybody declared. Five new entries
		// arrived at once here, so the collision is worth an assertion rather
		// than an eye.
		const orders = manifest.menu
			.filter((entry) => entry.section === 'settings')
			.map((entry) => entry.order)
		expect(new Set(orders).size).toBe(orders.length)
	})
})
