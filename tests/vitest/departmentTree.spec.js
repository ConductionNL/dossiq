/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqAfdelingen draws the departments as a tree. Nextcloud groups do not nest,
 * so the pane reads the tree from the organisation roles: each names its
 * Nextcloud group and its parent role. The manifest names no group.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/manifest.d/68-departments.json').pages.find((entry) => entry.id === 'Departments')
const role = read('lib/Settings/register.d/61-mandaat-matrix.json').components.schemas.organisatieRol

describe('the departments tree', () => {
	it('reads each group\'s parent from the organisation roles', () => {
		expect(page.config.folderSidebar.parentsFrom).toEqual({
			register: 'dossiq',
			schema: 'organisatieRol',
			groupField: 'ncGroupId',
			parentField: 'parentRoleId',
		})
	})

	it('names no group in the manifest', () => {
		expect(page.config.folderSidebar.groups).toBeUndefined()
	})

	it('points at fields the role schema has', () => {
		expect(role.properties).toHaveProperty('ncGroupId')
		expect(role.properties).toHaveProperty('parentRoleId')
	})

	it('names the team lead in the heading of a selected team, from the role', () => {
		expect(page.config.folderSidebar.headingParts).toEqual([{ field: 'teamLead', text: 'team lead {value}', format: 'user' }])
		expect(role.properties.teamLead).toMatchObject({ type: 'string', referenceType: 'nextcloud-user' })
		const nl = read('l10n/nl.json').translations
		expect(nl['team lead {value}']).toBe('teamleider {value}')
	})
})
