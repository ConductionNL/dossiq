/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTaak Historie: "Taak opgepakt door Pieter Jansen" with "6 okt 09:05" under it.
 *
 * @spec openspec/specs/task-page/spec.md#requirement-the-tasks-side-history-puts-the-moment-under-the-action
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const side = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages.find((page) => page.id === 'TaskDetail').config.sideColumn

describe('DqTaak side history', () => {
	it('stacks the moment under the action', () => {
		expect(side.find((entry) => entry.id === 'side-history').content.layout).toBe('stacked')
	})
})
