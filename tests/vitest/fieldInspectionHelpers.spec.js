/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure field-inspection helpers
 * (src/utils/fieldInspectionHelpers.js): GPS-accuracy classification,
 * photo/voice-memo limits, checklist required-field validation, progress
 * counting, and sync-indicator copy.
 *
 * The global `t()` is stubbed to return the English source string with
 * {placeholder} substitution so the copy is deterministically assertable.
 *
 * @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-automatic-gps-geolocation-tagging-on-all-fieldwork
 */

import { describe, it, expect, beforeAll } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text, vars) => {
		if (!vars) return text
		return text.replace(/\{(\w+)\}/g, (m, k) => (
			Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m
		))
	}
})

const load = async () => await import('../../src/utils/fieldInspectionHelpers.js')

describe('classifyGps', () => {
	it('returns good with no warning for an accurate fix', async () => {
		const { classifyGps } = await load()
		expect(classifyGps({ accuracy: 8 })).toEqual({ quality: 'good', source: 'sensor', warning: null })
	})

	it('returns poor with warning copy when accuracy >50m', async () => {
		const { classifyGps } = await load()
		const r = classifyGps({ accuracy: 200 })
		expect(r.quality).toBe('poor')
		expect(r.source).toBe('sensor')
		expect(r.warning).toContain('±200m')
	})

	it('treats exactly 50m as good (boundary)', async () => {
		const { classifyGps } = await load()
		expect(classifyGps({ accuracy: 50 }).quality).toBe('good')
	})

	it('returns sensorless when the sensor is unavailable', async () => {
		const { classifyGps } = await load()
		expect(classifyGps(null, false)).toEqual({ quality: 'sensorless', source: 'sensorless', warning: null })
		expect(classifyGps(null).quality).toBe('sensorless')
	})
})

describe('isPhotoWithinTarget', () => {
	it('accepts a photo at or below 2MB', async () => {
		const { isPhotoWithinTarget, PHOTO_MAX_BYTES } = await load()
		expect(isPhotoWithinTarget(PHOTO_MAX_BYTES)).toBe(true)
		expect(isPhotoWithinTarget(1.8 * 1024 * 1024)).toBe(true)
	})

	it('rejects a photo above 2MB', async () => {
		const { isPhotoWithinTarget, PHOTO_MAX_BYTES } = await load()
		expect(isPhotoWithinTarget(PHOTO_MAX_BYTES + 1)).toBe(false)
	})
})

describe('isVoiceMemoWithinLimit', () => {
	it('accepts a memo within 5 minutes', async () => {
		const { isVoiceMemoWithinLimit } = await load()
		expect(isVoiceMemoWithinLimit(154)).toBe(true)
		expect(isVoiceMemoWithinLimit(300)).toBe(true)
	})

	it('rejects a memo over 5 minutes or non-positive', async () => {
		const { isVoiceMemoWithinLimit } = await load()
		expect(isVoiceMemoWithinLimit(301)).toBe(false)
		expect(isVoiceMemoWithinLimit(0)).toBe(false)
		expect(isVoiceMemoWithinLimit(-5)).toBe(false)
	})
})

describe('validateChecklistAnswers', () => {
	const template = {
		items: [
			{ questionId: 'q001', type: 'yes_no', required: true },
			{ questionId: 'q002', type: 'photo_required', required: true },
			{ questionId: 'q003', type: 'yes_no', required: false },
			{ questionId: 'q004', type: 'text', required: false },
		],
	}

	it('passes when all required items are answered', async () => {
		const { validateChecklistAnswers } = await load()
		const r = validateChecklistAnswers(template, {
			q001: { answer: 'ja' },
			q002: { evidenceRefs: ['evidence-1'] },
		})
		expect(r.valid).toBe(true)
		expect(r.errors).toEqual([])
	})

	it('blocks a required yes_no left empty', async () => {
		const { validateChecklistAnswers } = await load()
		const r = validateChecklistAnswers(template, { q002: { evidenceRefs: ['e'] } })
		expect(r.valid).toBe(false)
		expect(r.errors.map((e) => e.questionId)).toContain('q001')
	})

	it('blocks a required photo question with no evidence', async () => {
		const { validateChecklistAnswers } = await load()
		const r = validateChecklistAnswers(template, { q001: { answer: 'ja' }, q002: { evidenceRefs: [] } })
		expect(r.valid).toBe(false)
		const err = r.errors.find((e) => e.questionId === 'q002')
		expect(err.message).toMatch(/Photo required/i)
	})

	it('ignores optional items even when empty', async () => {
		const { validateChecklistAnswers } = await load()
		const r = validateChecklistAnswers(template, {
			q001: { answer: 'nee' },
			q002: { evidenceRefs: ['e'] },
		})
		expect(r.valid).toBe(true)
	})

	it('treats whitespace-only answers as empty', async () => {
		const { validateChecklistAnswers } = await load()
		const r = validateChecklistAnswers(template, { q001: { answer: '   ' }, q002: { evidenceRefs: ['e'] } })
		expect(r.valid).toBe(false)
	})
})

describe('checklistProgress', () => {
	const template = {
		items: [
			{ questionId: 'q001', type: 'yes_no' },
			{ questionId: 'q002', type: 'photo_required' },
			{ questionId: 'q003', type: 'text' },
		],
	}

	it('counts answered + photo-evidenced items', async () => {
		const { checklistProgress } = await load()
		expect(checklistProgress(template, {
			q001: { answer: 'ja' },
			q002: { evidenceRefs: ['e'] },
		})).toEqual({ done: 2, total: 3 })
	})

	it('returns 0/total for no answers', async () => {
		const { checklistProgress } = await load()
		expect(checklistProgress(template, {})).toEqual({ done: 0, total: 3 })
	})
})

describe('syncIndicator', () => {
	it('shows red copy when offline', async () => {
		const { syncIndicator } = await load()
		const r = syncIndicator(12, false)
		expect(r.tone).toBe('error')
		expect(r.text).toContain('12')
	})

	it('shows amber copy when pending while online', async () => {
		const { syncIndicator } = await load()
		const r = syncIndicator(3, true)
		expect(r.tone).toBe('warning')
		expect(r.text).toContain('3')
	})

	it('shows green copy when everything is synced', async () => {
		const { syncIndicator } = await load()
		expect(syncIndicator(0, true)).toEqual({ tone: 'success', text: 'All changes synced' })
	})
})
