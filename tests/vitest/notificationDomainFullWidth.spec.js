/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqPersoonlijkeInstellingen Meldingen: the Meldingsdomein select runs the
 * section's width, as the board draws it (live: 360px).
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/NotificationRoutingSettings.vue'), 'utf8')

describe('the notification domain select', () => {
	it('runs the section\'s width', () => {
		const at = source.indexOf('.notification-routing__scope :deep(.v-select.select) {')
		const body = source.slice(at, source.indexOf('}', at))
		expect(body).toContain('width: 100%;')
		expect(body).not.toContain('360px')
	})

	it('stands 18px above the matrix, as drawn: no bottom margin under its ring', () => {
		const at = source.indexOf('.notification-routing__scope :deep(.v-select.select) {')
		const body = source.slice(at, source.indexOf('}', at))
		expect(body).toContain('margin-block-end: 0;')
	})
})
