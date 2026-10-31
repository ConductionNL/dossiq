/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie: each latest contact moment reads
 * "4 okt, 10:52 · e-mail · inkomend · zaak 2026-0070", the count sits at the
 * right of the card title.
 *
 * @spec openspec/changes/party-contact-moments-board-lines/specs/party-pages/spec.md#requirement-a-partys-latest-contact-moments-read-moment-channel-direction-and-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe.each(['ContactDetail', 'OrganisationDetail'])('%s latest contact moments', (id) => {
	const content = pages.find((page) => page.id === id).config.widgets.find((widget) => widget.id === 'contact-latest-moments').content

	it('builds the meta line from moment, channel, direction and case', () => {
		expect(content.meta.parts).toEqual(['{startTime|datetime}', '{notificationChannel}', '{direction}', 'case {case.identifier}'])
		expect(content.extend).toContain('case')
	})

	it('names channel and direction in the board words', () => {
		expect(nl[content.valueLabels.notificationChannel.email]).toBe('e-mail')
		expect(nl[content.valueLabels.notificationChannel.phone]).toBe('telefoon')
		expect(nl[content.valueLabels.direction.inbound]).toBe('inkomend')
		expect(nl[content.valueLabels.direction.outbound]).toBe('uitgaand')
		expect(nl['case {case.identifier}']).toBe('zaak {case.identifier}')
	})

	it('puts the count at the right of the title', () => {
		expect(content.headerNote).toBe('{total} in total')
		expect(content.headerSubtitle).toBeUndefined()
	})
})
