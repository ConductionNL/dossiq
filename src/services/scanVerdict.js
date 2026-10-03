// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// How a virus scan verdict reads on a document row and in its properties.
//
// dossiq scans nothing. `files_antivirus` checks the node and records what it
// found, `ScanVerdictReader` reads that back, and this is the one place the
// answer is turned into a sentence, so the Files tab column and the document
// properties dialog cannot word it differently.
//
// 🔴 NOT SCANNED IS NOT A CLEAN BILL OF HEALTH (company ADR-102). A file
// nobody checked reads Not scanned, in the same words whether the scanner is
// missing from the instance or has simply not reached the file, and never as
// anything a reader could mistake for cleared. Absence of a verdict is
// absence of a verdict.

/**
 * The scanner checked this file and found nothing.
 *
 * @type {string}
 */
export const STATE_CLEAN = 'clean'

/**
 * The scanner checked this file and found something.
 *
 * @type {string}
 */
export const STATE_INFECTED = 'infected'

/**
 * Nobody has told us this file is either.
 *
 * @type {string}
 */
export const STATE_NOT_SCANNED = 'not-scanned'

/**
 * The state carried by a verdict, whatever shape it arrived in.
 *
 * The endpoint answers an object; a column may hand the formatter the bare
 * state. Anything else is read as not scanned, because a shape this module
 * does not recognise is not evidence of a clean file.
 *
 * @param {object|string} verdict The verdict, or the bare state.
 * @return {string} One of the three states.
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
export function stateOf(verdict) {
	const raw = typeof verdict === 'string' ? verdict : verdict?.state
	if (raw === STATE_CLEAN || raw === STATE_INFECTED) {
		return raw
	}
	return STATE_NOT_SCANNED
}

/**
 * The moment of the check, as a short local time, or an empty string.
 *
 * @param {object|string} verdict The verdict.
 * @return {string} The time, or an empty string when none was recorded.
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
export function scannedAtOf(verdict) {
	const raw = typeof verdict === 'string' ? '' : verdict?.scannedAt
	if (typeof raw !== 'string' || raw === '') {
		return ''
	}
	const moment = new Date(raw)
	if (Number.isNaN(moment.getTime())) {
		return ''
	}
	return moment.toLocaleTimeString(undefined, {
		hour: '2-digit',
		minute: '2-digit',
	})
}

/**
 * What the Scan column and the properties dialog say about one file.
 *
 * @param {object|string} verdict The verdict.
 * @return {string} The sentence.
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
export function scanVerdictLabel(verdict) {
	const state = stateOf(verdict)
	if (state === STATE_INFECTED) {
		return t('dossiq', 'Infected')
	}
	if (state === STATE_CLEAN) {
		const at = scannedAtOf(verdict)
		if (at === '') {
			return t('dossiq', 'Clean')
		}
		return t('dossiq', 'Clean, {time}', { time: at })
	}
	return t('dossiq', 'Not scanned')
}
