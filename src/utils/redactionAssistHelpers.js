/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure presentation helpers for RedactionAssistDialog.vue
 * (woo-llm-anonymisation) — span preview text, initial checkbox selection
 * state, and selected-span filtering. Extracted from the component so the
 * rules-floor-aware selection behaviour (rule spans always selected) is
 * independently testable without mounting Vue, mirroring
 * src/utils/assistantHelpers.js's convention.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-4-1
 */

/**
 * Build a short preview snippet around a span's offsets. Purely a display
 * aid — never used for the actual redaction, and deliberately tolerant of
 * offsets that may drift a few characters for multi-byte text (design.md
 * Decision 6): offsets are clamped into range rather than throwing.
 *
 * @param {string} text The full document text.
 * @param {{start: number, end: number}} span The span.
 * @param {number} [context] Characters of context to include on each side.
 * @return {string} The preview snippet, ellipsis-bounded.
 */
export function buildSpanPreview(text, span, context = 10) {
	const safeText = typeof text === 'string' ? text : ''
	const start = Math.max(
		0,
		Math.min(safeText.length, (span?.start ?? 0) - context),
	)
	const end = Math.max(0, Math.min(safeText.length, (span?.end ?? 0) + context))
	return '…' + safeText.slice(start, end) + '…'
}

/**
 * Build the initial per-span selection map for a freshly-fetched proposal:
 * every span starts selected (rule spans are ALSO always selected — the UI
 * merely disables their checkbox rather than defaulting them off, mirroring
 * the backend's rules-floor invariant that a rule span is never optional).
 *
 * @param {Array<{source: string}>} spans The proposal's spans, in order.
 * @return {Record<number, boolean>} Map of span index → selected.
 */
export function buildInitialSelections(spans) {
	const selections = {}
	;(spans || []).forEach((span, index) => {
		selections[index] = true
	})
	return selections
}

/**
 * Filter a proposal's spans down to the currently-selected ones, in the
 * shape the review endpoint expects.
 *
 * @param {Array<object>} spans The proposal's spans, in order.
 * @param {Record<number, boolean>} selections Map of span index → selected.
 * @return {Array<object>} The selected spans only.
 */
export function filterSelectedSpans(spans, selections) {
	return (spans || []).filter((_, index) => selections[index] === true)
}

/**
 * Whether a span's checkbox may be toggled — rule-floor spans are always
 * applied and their checkbox is disabled (mirrors the backend invariant
 * that a rule span can never be excluded from a merged proposal).
 *
 * @param {{source: string}} span The span.
 * @return {boolean}
 */
export function isSpanToggleable(span) {
	return span?.source !== 'rule'
}
