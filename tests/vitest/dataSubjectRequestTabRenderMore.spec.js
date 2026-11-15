// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the data subject request tab draws: the server's own refusal with its
 * rule, the outcome card coloured by whether the erasure finished, the run
 * button only once a preview exists, and the export section's expired and
 * still-assembling states. Only the HTTP boundary is mocked; the tab runs the
 * real services/dataSubjectRequestApi.js against it, so the urls and the
 * refusal shape are the ones shipped.
 *
 * @spec openspec/specs/avg-processing-surface/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

/**
 * A stand-in that renders its default slot and keeps its props as attributes.
 *
 * @param {string} name The component name.
 * @param {string} tag The element it renders.
 * @return {object} The component.
 */
function slotStub (name, tag) {
  return {
	name,
	inheritAttrs: false,
	props: ['type', 'variant', 'disabled', 'href', 'name', 'description'],
	emits: ['click'],
	template: `<${tag} :data-type="type" :data-variant="variant" :data-disabled="disabled ? 'true' : null" :data-href="href" :data-name="name" @click="$emit('click')"><slot /></${tag}>`,
}
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: slotStub('NcButton', 'button') }))
vi.mock('@nextcloud/vue/components/NcEmptyContent', () => ({ default: slotStub('NcEmptyContent', 'div') }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: slotStub('NcNoteCard', 'aside') }))

const { default: DataSubjectRequestTab } =
	await import('../../src/views/cases/components/DataSubjectRequestTab.vue')

/**
 * Mount the tab and let it load.
 *
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(props) {
	const w = mount(DataSubjectRequestTab, { props })
	await flushPromises()
	return w
}

/**
 * The buttons' texts, trimmed.
 *
 * @param {object} w The wrapper.
 * @return {string[]} The labels.
 */
const buttonLabels = (w) => w.findAll('button').map((b) => b.text().trim())

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
})

describe('DataSubjectRequestTab on an erasure', () => {
	const erasure = { dataSubjectRequestType: 'verwijdering' }

	it('offers only the preview until the platform has answered one', async () => {
		const w = await mountTab({ objectId: 'c1', object: erasure })

		expect(buttonLabels(w)).toEqual(['Take the preview'])
		expect(mockGet).not.toHaveBeenCalled()
	})

	it('posts the preview to the case\'s avg endpoint and then offers the run', async () => {
		mockPost.mockResolvedValueOnce({ data: { report: { counts: { erase: { cases: 1 } }, protected: [] } } })
		const w = await mountTab({ objectId: 'c 1', object: erasure })

		await w.findAll('button')[0].trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/c%201/avg/erasure-preview')
		expect(buttonLabels(w)).toEqual(['Take the preview', 'Run the approved erasure'])
		expect(w.findAll('button')[1].attributes('data-variant')).toBe('warning')
	})

	it('runs the erasure and draws an unfinished outcome as a warning', async () => {
		mockPost.mockResolvedValueOnce({ data: { complete: false, withheld: [1], refused: [], failed: [2] } })
		const w = await mountTab({ objectId: 'c1', object: { ...erasure, erasureCounts: { erase: { cases: 1 } } } })

		await w.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/c1/avg/erasure-run')
		const card = w.findAll('aside').at(-1)
		expect(card.attributes('data-type')).toBe('warning')
		expect(card.text()).toBe('The erasure did not finish. 2 records still hold this person.')
	})

	it('draws a finished outcome as success', async () => {
		const w = await mountTab({ objectId: 'c1', object: { ...erasure, erasureOutcome: { complete: true } } })

		const card = w.find('aside')
		expect(card.attributes('data-type')).toBe('success')
		expect(card.text()).toBe('The platform reports the erasure complete.')
	})

	it('shows the server\'s refusal message and rule, and is no longer busy', async () => {
		mockPost.mockRejectedValueOnce({ response: { data: { error: 'erasure-not-approved', message: 'Approve the erasure first.' } } })
		const w = await mountTab({ objectId: 'c1', object: erasure })

		await w.findAll('button')[0].trigger('click')
		await flushPromises()

		const card = w.find('aside')
		expect(card.attributes('data-type')).toBe('warning')
		expect(card.text()).toContain('Approve the erasure first.')
		expect(card.find('.dsr-tab__rule').text()).toBe('erasure-not-approved')
		expect(w.vm.busy).toBe(false)
		expect(w.findAll('button')[0].attributes('data-disabled')).toBeUndefined()
	})

	it('falls back to the default refusal when the server says nothing', async () => {
		mockPost.mockRejectedValueOnce(new Error('network'))
		const w = await mountTab({ objectId: 'c1', object: erasure })

		await w.vm.takePreview()

		expect(w.vm.refusal).toEqual({
			rule: 'data-subject-request-failed',
			message: 'This could not be completed. Nothing was erased.',
		})
	})
})

describe('DataSubjectRequestTab on an access request', () => {
	it('reads the export state on mount and offers the download once it is served', async () => {
		mockGet.mockResolvedValueOnce({ data: { exportId: 'e/1', downloadable: true, expired: false } })
		const w = await mountTab({ objectId: 'c1', object: { dataSubjectRequestType: 'inzage' } })

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/c1/avg/subject-export')
		expect(buttonLabels(w)).toEqual(['Ask for the export', 'Download the export'])
		expect(w.findAll('button')[1].attributes('data-href'))
			.toBe('/apps/openregister/api/gdpr/subject-exports/e%2F1/download')
	})

	it('says the export expired and offers no download', async () => {
		mockGet.mockResolvedValueOnce({ data: { exportId: 'e1', downloadable: false, expired: true } })
		const w = await mountTab({ objectId: 'c1', object: { dataSubjectRequestType: 'correctie' } })

		expect(w.find('aside').text()).toBe('This export expired. Ask for it again.')
		expect(w.text()).not.toContain('The platform is still assembling the file.')
		expect(buttonLabels(w)).toEqual(['Ask for the export'])
	})

	it('says the file is still being assembled while an export exists but is not served', async () => {
		mockGet.mockResolvedValueOnce({ data: { exportId: 'e1', downloadable: false, expired: false } })
		const w = await mountTab({ objectId: 'c1', object: { dataSubjectRequestType: 'inzage' } })

		expect(w.find('p').text()).toBe('The platform is still assembling the file.')
		expect(w.find('aside').exists()).toBe(false)
	})

	it('asks for the export, then reads its state again', async () => {
		mockGet.mockResolvedValueOnce({ data: { exportId: '', downloadable: false, expired: false } })
		mockPost.mockResolvedValueOnce({ data: {} })
		mockGet.mockResolvedValueOnce({ data: { exportId: 'e9', downloadable: true, expired: false } })
		const w = await mountTab({ objectId: 'c1', object: { dataSubjectRequestType: 'inzage' } })

		await w.findAll('button')[0].trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/c1/avg/subject-export')
		expect(mockGet).toHaveBeenCalledTimes(2)
		expect(w.vm.exportState.exportId).toBe('e9')
	})
})

describe('DataSubjectRequestTab on any other case', () => {
	it('says there is nothing to erase or export and asks the platform nothing', async () => {
		const w = await mountTab({ objectId: 'c1' })

		expect(w.find('div[data-name]').attributes('data-name')).toBe('Not a data subject request')
		expect(w.findAll('button')).toHaveLength(0)
		expect(mockGet).not.toHaveBeenCalled()
		expect(mockPost).not.toHaveBeenCalled()
	})
})
