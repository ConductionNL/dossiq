// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * A case on Assigned to me that the reader covers names whom for, and until
 * when (board DqAanMijToegewezen, "Voor Anouk Bakker, tot 10 okt"); the header
 * says how many of the cases are covered ("waarvan 1 als waarnemer").
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { coveredForLabel } from '../../src/services/coveredFor.js'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters } = await import('../../src/services/formatters.js')

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const myWork = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews.find((view) => view.slug === 'my-work')

describe('the covered-for line', () => {
	it('names the absentee and the last day, the day as the locale writes it', () => {
		expect(coveredForLabel({ name: 'Anouk Bakker', until: '2026-10-10' }, 'nl')).toBe('For Anouk Bakker, until 10 okt')
	})

	it('leaves the day out of an open-ended cover', () => {
		expect(coveredForLabel({ name: 'Anouk Bakker', until: '' }, 'nl')).toBe('For Anouk Bakker')
	})

	it('says nothing for the reader\'s own case', () => {
		expect(coveredForLabel(null, 'nl')).toBe('')
		expect(coveredForLabel({ absentee: 'anouk' }, 'nl')).toBe('')
	})

	it('is the formatter the card\'s note line reads coveredFor through', () => {
		expect(typeof formatters.coveredFor).toBe('function')
		expect(myWork.overrides.cardLayout.note).toMatchObject({ field: 'coveredFor', formatter: 'coveredFor' })
		expect(nl['For {name}, until {date}']).toBe('Voor {name}, tot {date}')
		expect(nl['For {name}']).toBe('Voor {name}')
	})

	it('counts the covered cases in the header, left out while there are none', () => {
		expect(myWork.overrides.headerMeta).toBe('{total} cases in your name[, {rows:coveredFor} of them as a substitute] · most urgent first')
		expect(nl[myWork.overrides.headerMeta]).toBe('{total} zaken op uw naam[, waarvan {rows:coveredFor} als waarnemer] · meest urgente eerst')
	})
})
