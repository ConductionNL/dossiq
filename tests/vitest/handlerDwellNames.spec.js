/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Process analysis "Dwell time by handler" names each handler by display name,
 * as DqProcesanalyse draws it ("Pieter Jansen"), not by user id. The API's
 * actorLabel is the user id, or the "not recorded" label; the userName
 * formatter returns a value it cannot resolve as written.
 *
 * @spec exclude board DqProcesanalyse handler column, configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'ProcessMiningDashboard')
const widget = page.config.widgets.find((entry) => entry.id === 'pm-dwell-by-assignee')

describe('process analysis dwell time by handler', () => {
	it('shows the handler by display name', () => {
		const column = widget.content.columns.find((entry) => entry.key === 'actorLabel')
		expect(column.formatter).toBe('userName')
	})
})
