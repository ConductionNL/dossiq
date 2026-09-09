/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the host-mounted object sidebar is given.
 *
 * `CnObjectSidebar` is mounted here rather than by `CnAppRoot`, so every prop
 * the sidebar's tab widgets need has to be forwarded by hand — and a missing
 * one fails SILENTLY. A `data` tab renders `CnObjectDataWidget`, which builds
 * its field list from the schema OBJECT and its values from the loaded record;
 * given neither, `fieldsFromSchema` returns an empty list and the tab renders
 * "No data available" on a record that is perfectly fine.
 *
 * That is what happened to the Tags tab: it could not show a tag, and could not
 * offer the Click-to-edit that adds a first one. `CnDetailPage` publishes both
 * values into the shared `objectSidebarState` (`schemaObject` and `object`)
 * for exactly this purpose; App.vue passed the slugs beside them and not these.
 *
 * Asserted against the template source rather than by mounting: mounting App
 * pulls in CnAppRoot, the router and the whole store layer to check two
 * bindings, and the binding is the thing that was missing.
 *
 * @spec openspec/changes/case-identity/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const app = fs.readFileSync(path.join(ROOT, 'src', 'App.vue'), 'utf8')

/** The `<CnObjectSidebar …>` opening tag. @return {string} Its source. */
function sidebarTag() {
	const m = app.match(/<CnObjectSidebar\b[\s\S]*?\/>/)
	expect(m, 'App.vue still mounts CnObjectSidebar').toBeTruthy()
	return m[0]
}

describe('the host-mounted object sidebar', () => {
	it('is given the resolved schema object, not only the slug', () => {
		expect(sidebarTag()).toContain(
			':objectSchema="objectSidebarState.schemaObject"',
		)
	})

	it('is given the loaded record', () => {
		expect(sidebarTag()).toContain(':objectData="objectSidebarState.object"')
	})

	it('still passes the slugs, which other tabs read', () => {
		// The two are not alternatives: the registry tabs address the record by
		// register and schema SLUG, and only the data widget needs the objects.
		const tag = sidebarTag()
		expect(tag).toContain(':register="objectSidebarState.register"')
		expect(tag).toContain(':schema="objectSidebarState.schema"')
	})
})
