/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContact "Bereikbaar via" writes the last contact and the
 * registration note as one muted paragraph; the note still shows without a
 * contact moment (nextcloud-vue facts joinPrevious).
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-reachable-via-is-one-paragraph
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fields = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'ContactDetail').config.sideColumn.find((widget) => widget.id === 'side-reach').content.fields

describe('DqContact Bereikbaar via', () => {
	it('writes the last contact as a muted line and joins the note to it', () => {
		const note = fields[fields.length - 1]
		expect(fields[fields.length - 2].as).toBe('line')
		expect(note).toMatchObject({ as: 'line', from: 'object', joinPrevious: true, joinSeparator: '. ' })
	})
})
