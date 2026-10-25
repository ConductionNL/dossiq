/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaak: the Zaak card names the case handler, not the user id.
 *
 * @spec openspec/changes/task-case-card-handler-name/specs/task-page/spec.md#requirement-the-tasks-case-card-names-the-case-handler
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const side = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'TaskDetail').config.sideColumn

describe('DqTaak Zaak card', () => {
	it('shows the case handler by display name', () => {
		const owner = side.find((entry) => entry.id === 'side-case').content.fields.find((field) => field.key === 'case-owner')
		expect(owner.parts.find((part) => part.path === 'assignee').format).toBe('user')
	})
})
