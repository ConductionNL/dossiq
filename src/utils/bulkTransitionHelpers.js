/**
 * Bulk status-transition helper utilities.
 *
 * Pure logic extracted from BulkTransitionDialog.vue so request-payload
 * shaping is unit-testable without mounting a component.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */

/**
 * The parameters a status transition needs as a bulk job.
 *
 * The comment travels as a parameter rather than as the job's justification:
 * the job's justification is the record of why the ACT was ordered, and the
 * comment is what lands on each case's own timeline. They are usually the same
 * sentence and are still two different things.
 *
 * @param {string} transitionId The transition to run.
 * @param {string} [comment] What to write on each case.
 *
 * @return {{transitionId: string, comment: string}} The job parameters.
 *
 * @spec openspec/specs/case-management/spec.md
 */
export function transitionParameters(transitionId, comment) {
	return { transitionId: transitionId || '', comment: comment || '' }
}

/**
 * The parameters a lifecycle gesture needs as a bulk job.
 *
 * `days` goes only with suspend and `newEndDate` only with extend. A key the
 * gesture has no use for is noise in the audit trail and a value the next
 * reader has to explain away.
 *
 * The reason is a parameter AND the job's justification. The job stores it as
 * the record of the act; the per-case write reads it from the parameters,
 * because a bulk action is handed its parameters and not its job.
 *
 * @param {string} gesture One of suspend, resume, extend.
 * @param {{reason?: string, days?: (number|string), newEndDate?: string}} [fields] The dialog's fields.
 *
 * @return {object} The job parameters.
 *
 * @spec openspec/specs/case-management/spec.md
 */
export function lifecycleParameters(gesture, fields) {
	const given = fields || {}
	const parameters = {
		gesture: gesture || '',
		reason: (given.reason || '').trim(),
	}

	if (gesture === 'suspend') {
		parameters.days = Number(given.days) || 0
	}

	if (gesture === 'extend') {
		parameters.newEndDate = given.newEndDate || ''
	}

	return parameters
}

/**
 * The lifecycle gestures a bulk call may ask for, beside a transition.
 *
 * A transition is not one of them: it moves `case.status` and goes through
 * the status engine, which is that field's only write path. These three move
 * the statutory CLOCK instead (opschorting under Awb 4:5, hervatting,
 * verlenging under Awb 4:14) and go through `CaseLifecycleService` — the
 * same single-case gestures the case page's Actions menu calls.
 */
export const LIFECYCLE_GESTURES = ['suspend', 'resume', 'extend']

/**
 * Whether a dialog mode is one of the lifecycle gestures.
 *
 * @param {string} mode The dialog mode.
 * @return {boolean} True for suspend / resume / extend.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */
export function isLifecycleGesture(mode) {
	return LIFECYCLE_GESTURES.includes(mode)
}

/**
 * The transitions every case offers, in the first case's order.
 *
 * @param {Array<Array<{id: string}>>} offers What each case offers.
 *
 * @return {Array<object>} The transitions on offer in all of them.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
 */
export function commonTransitions(offers) {
	const [first = [], ...rest] = offers

	return first.filter((transition) =>
		rest.every((offer) => offer.some((other) => other.id === transition.id)),
	)
}

/**
 * One row of the table of changes.
 *
 * @param {string} id The case id.
 * @param {object} [object] The case.
 * @param {object} [current] The case's current status as the engine reports it.
 *
 * @return {{id: string, title: string, identifier: string, status: string, deadline: string}} The row.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md#requirement-the-status-dialog-shows-what-changes
 */
export function changeRow(id, object, current) {
	const deadline = object?.deadline ? new Date(object.deadline) : null

	return {
		id,
		title: object?.title || id,
		identifier: object?.identifier || '',
		status: current?.statusName || '—',
		deadline: deadline && !isNaN(deadline) ? deadline.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '—',
	}
}
