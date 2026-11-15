/**
 * Task validation utilities for create and update operations.
 */

import { validateTransition } from './taskLifecycle.js'

/**
 * Validate a task creation form.
 *
 * @param {object} form The form data
 * @return {{ valid: boolean, errors: object }} Validation result
 * @spec openspec/specs/task-management/spec.md
 */
export function validateTaskCreate(form) {
	const errors = titleErrors(form)
	if (!form.case) {
		errors.case = t('dossiq', 'Case is required')
	}

	return validation(errors)
}

/**
 * Validate a task update form.
 *
 * @param {object} form The form data
 * @return {{ valid: boolean, errors: object }} Validation result
 * @spec openspec/specs/task-management/spec.md
 */
export function validateTaskUpdate(form) {
	return validation(titleErrors(form))
}

/**
 * The title error of a task form, when its title is missing or blank.
 *
 * @param {object} form The form data
 * @return {object} `{ title }`, or an empty object
 */
function titleErrors(form) {
	const errors = {}
	if (!form.title || !form.title.trim()) {
		errors.title = t('dossiq', 'Title is required')
	}
	return errors
}

/**
 * A validation result over its errors.
 *
 * @param {object} errors The errors by field
 * @return {{ valid: boolean, errors: object }} Valid when there are none
 */
function validation(errors) {
	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

/**
 * Validate a task status transition.
 *
 * @param {string} from Current status
 * @param {string} to Target status
 * @return {{ valid: boolean, error: string|null }} Validation result
 * @spec openspec/specs/task-management/spec.md
 */
export function validateTaskTransition(from, to) {
	if (!from || !to) {
		return { valid: false, error: t('dossiq', 'Invalid status transition') }
	}

	if (!validateTransition(from, to)) {
		return { valid: false, error: refusedTransition(from, to) }
	}

	return { valid: true, error: null }
}

const TERMINAL_STATUSES = ['completed', 'terminated', 'disabled']

/**
 * Why a move the lifecycle refuses is refused: a task not yet started, a
 * terminal task, or any other move.
 *
 * @param {string} from Current status
 * @param {string} to Target status
 * @return {string} The refusal, translated
 */
function refusedTransition(from, to) {
	if (to === 'completed' && from === 'available') {
		return t('dossiq', 'A task must be active before it can be completed. Start the task first.')
	}
	if (TERMINAL_STATUSES.includes(from)) {
		return t('dossiq', 'Cannot change status of a {status} task. Terminal states cannot be reversed.', { status: from })
	}
	return t('dossiq', "Cannot transition from '{from}' to '{to}'", { from, to })
}
