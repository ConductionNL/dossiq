import { flushPromises, mount } from '@vue/test-utils'
/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The archive dialog (board DqArchiveren): the checklist is read off the case,
 * only an ended case with a reason and the archive role can be archived, and
 * it posts the same archive act the Lifecycle dialog posts.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/services/caseSummary.js', () => ({
	caseEyebrow: (act) => act,
	fetchCaseSummary: async () => ({ title: 'Verlichting fietspad', identifier: '2026-0082', wooPublicationStatus: 'published' }),
}))

const axios = (await import('@nextcloud/axios')).default
const { default: CaseArchiveDialog, checklistRow } = await import('../../src/dialogs/CaseArchiveDialog.vue')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))

/**
 * Mount the dialog over the given answers.
 *
 * @param {object} answers What the reads answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog({ ended = true, allowed = true, archived = false, checks = [] } = {}) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/archive/readiness')) {
			return Promise.resolve({ data: { checklist: [{ id: 'ended', met: ended }, { id: 'documents', met: false, count: 6, total: 7 }, { id: 'openTasks', met: null }] } })
		}
		return Promise.resolve({ data: { archived, acts: [{ act: 'archive', allowed, reason: allowed ? '' : 'Archiving needs the archivist role.' }] } })
	})
	const wrapper = mount(CaseArchiveDialog, {
		props: { caseId: 'case-1', checks },
		global: {
			stubs: {
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: { props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcTextArea: { props: ['modelValue'], emits: ['update:modelValue'], template: '<textarea @input="$emit(\'update:modelValue\', $event.target.value)" />' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('CaseArchiveDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('draws the checklist from the case, and an unreadable item as unknown', async () => {
		const wrapper = await mountDialog({ checks: [{ id: 'published', label: 'Publication done', field: 'wooPublicationStatus', value: 'published' }] })

		expect(wrapper.find('[data-testid="case-archive-check-documents"]').text()).toContain('6 of 7 final')
		expect(wrapper.find('[data-testid="case-archive-check-openTasks"]').text()).toContain('Could not be read')
		expect(wrapper.vm.checklist.find((row) => row.id === 'published').met).toBe(true)
	})

	it('posts the archive act with the reason once the case has ended', async () => {
		const wrapper = await mountDialog()
		axios.post.mockResolvedValue({ data: { archiveStatus: 'archived' } })

		await wrapper.find('textarea').setValue('Dossier compleet.')
		await wrapper.find('[data-testid="case-archive-confirm"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/apps/dossiq/api/case/case-1/archive'), { reason: 'Dossier compleet.' })
		expect(wrapper.find('[data-testid="case-archive-done"]').exists()).toBe(true)
	})

	it('offers nothing to archive on an open case', async () => {
		const wrapper = await mountDialog({ ended: false })
		await wrapper.find('textarea').setValue('Te vroeg.')

		expect(wrapper.find('[data-testid="case-archive-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('says which role is missing instead of offering the act', async () => {
		const wrapper = await mountDialog({ allowed: false })

		expect(wrapper.find('[data-testid="case-archive-refusal"]').text()).toBe('Archiving needs the archivist role.')
		expect(wrapper.find('[data-testid="case-archive-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('offers nothing on a case that is already archived, and says so', async () => {
		const wrapper = await mountDialog({ archived: true })
		await wrapper.find('textarea').setValue('Nog eens.')

		expect(wrapper.find('[data-testid="case-archive-already"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-archive-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('labels the server items', () => {
		expect(checklistRow({ id: 'decision', met: false }).label).toBe('Decision made')
		expect(checklistRow({ id: 'openTasks', met: true, count: 0 }).label).toBe('Open tasks: 0')
	})

	it('is in More on an ended case, and on the closed stage card, beside Lifecycle', () => {
		const actions = manifest.pages.find((page) => page.id === 'CaseDetail').config.headerActions
		const archive = actions.find((action) => action.id === 'case-archive')

		expect(archive.target).toBe('CaseArchiveDialog')
		expect(archive.visibleWhen.all).toContainEqual({ field: 'endingAct', op: 'notEmpty' })
		expect(actions.some((action) => action.target === 'CaseLifecycleMenuDialog')).toBe(true)
		expect(JSON.stringify(simple)).toContain('"target":"CaseArchiveDialog"')
		expect(JSON.stringify(simple)).toContain('"id":"stage-archive","props":{"caseId":"@objectId"},"visibleWhen":{"field":"@self.archived","op":"empty"}')
	})
})
