/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqPostvak has a Filter button with the active filter count: the mailbox has
 * the index side panel, whose filter narrows the endpoint by the channel it
 * already takes (`sourceConfig.searchFields.channel`).
 *
 * @spec exclude board layout: DqPostvak is the specification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const file = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '88-postvak.json'), 'utf8'))
const config = file.pages.find((page) => page.id === 'Postvak').config

describe('DqPostvak filter panel', () => {
	it('has the side panel', () => {
		expect(config.sidebar && config.sidebar.enabled).toBe(true)
	})

	it('filters on the channel the endpoint takes, with its labels', () => {
		const channel = config.sidebar.fields.channel
		expect(channel).toMatchObject({ facetable: true, inputControl: 'select', enum: ['email', 'portal', 'webform'] })
		expect(channel.enumLabels).toEqual({ email: 'E-mail', portal: 'Portal', webform: 'Web form' })
		expect(Object.keys(config.sidebar.fields).every((key) => key in config.sourceConfig.searchFields)).toBe(true)
	})
})
