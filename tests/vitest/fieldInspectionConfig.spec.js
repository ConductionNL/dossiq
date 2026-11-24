// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { buildChecklistSubmission } from '@conduction/nextcloud-vue/src/integrations/offline/checklistSubmission.js'
import { normaliseChecklistTemplate } from '@conduction/nextcloud-vue/src/integrations/offline/fieldCollectionHelpers.js'
/**
 * dossiq's offline field-inspection mapping, run through the leaf's own pure
 * helpers: a template in dossiq's section shape reads into the leaf's items,
 * and a finished run replays as one POST to the case's result endpoint in the
 * shape `TaskChecklistController::submit()` reads.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */
import { describe, expect, it } from 'vitest'
import { DOSSIQ_FIELD_INSPECTION_CONFIG as config } from '../../src/utils/fieldInspectionConfig.js'

const template = {
	id: 'tpl-1',
	name: 'Fundering',
	version: 2,
	sections: [
		{ name: 'Wapening', order: 0, items: [
			{ id: 'w1', label: 'Wapening volgens tekening?', responseType: 'yes_no_na', required: true, photoRequired: 'if_no' },
			{ id: 'w2', label: 'Foto van de dekking', responseType: 'photo', photoRequired: 'altijd' },
		] },
		{ name: 'Beton', order: 1, items: [
			{ id: 'b1', label: 'Opmerking', responseType: 'text' },
			{ label: 'Item zonder id' },
		] },
	],
}

describe('dossiq field-inspection config', () => {
	// @spec openspec/changes/inspection-conflict-card/design.md (open decision 4)
	it('files a replay conflict in the register as a conflictRecord', () => {
		expect(config.register).toBe('dossiq')
		expect(config.conflictSchema).toBe('conflictRecord')
	})

	it('reads the one template schema, not the older checklist or result schemas', () => {
		expect(config.referenceSchema).toBe('inspectionChecklistTemplate')
		expect(config.resultSchema).toBe('')
		expect(Object.values(config)).not.toContain('checklistResult')
		expect(Object.values(config)).not.toContain('inspectionChecklist')
	})

	it('flattens the sections into items keyed by the stable item id', () => {
		const read = normaliseChecklistTemplate(template, config)

		expect(read.items.map((item) => [item.questionId, item.text, item.type, item.photoRequired])).toEqual([
			['w1', 'Wapening volgens tekening?', 'yes_no', false],
			['w2', 'Foto van de dekking', 'photo_required', true],
			['b1', 'Opmerking', 'text', false],
		])
		expect(read.items[0].required).toBe(true)
		expect(read.id).toBe('tpl-1')
	})

	it('replays a finished run as one POST to the case result endpoint', () => {
		const run = {
			plannedItem: { id: 'fi-9', caseRef: 'case-42', checklistTemplateRef: 'tpl-1' },
			template: normaliseChecklistTemplate(template, config),
			answers: {
				w1: { answer: 'no', evidenceRefs: ['file-1'] },
				w2: { evidenceRefs: ['file-2'] },
				b1: { answer: '' },
			},
			gps: { lat: 52.1, lng: 5.1, accuracy: 8 },
			capturedAt: '2026-11-17T09:00:00Z',
			capturedOffline: true,
		}

		const operation = buildChecklistSubmission(config, run)

		expect(operation.operationType).toBe('submit')
		expect(operation.endpoint).toBe('/apps/dossiq/api/cases/case-42/checklist-results')
		expect(operation.payload.checklistId).toBe('tpl-1')
		expect(operation.payload.inspection).toBe('fi-9')
		expect(operation.payload.capturedOffline).toBe(true)
		expect(operation.payload.capturedAt).toBe('2026-11-17T09:00:00Z')
		expect(operation.payload.location).toEqual({ lat: 52.1, lng: 5.1, accuracy: 8, source: 'sensor' })
		expect(operation.payload.items.map((item) => [item.itemId, item.value, item.photos])).toEqual([
			['w1', 'no', ['file-1']],
			['w2', '', ['file-2']],
		])
	})

	it('keeps a run without a case on the device instead of sending it elsewhere', () => {
		const run = { plannedItem: { id: 'fi-9' }, template: normaliseChecklistTemplate(template, config), answers: {} }

		expect(() => buildChecklistSubmission(config, run)).toThrow()
	})
})
