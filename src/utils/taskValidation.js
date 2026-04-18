// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Task validation utilities for create and update operations.
 * @spec openspec/changes/task-management/tasks.md#task-TM-01
 */

import { validateTransition } from './taskLifecycle.js'

/**
 * Validate a task creation form.
 *
 * @param {object} form The form data
 * @return {{ valid: boolean, errors: object }} Validation result
 */
export function validateTaskCreate(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('procest', 'Title is required')
	}

	if (!form.case) {
		errors.case = t('procest', 'Case is required')
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

/**
 * Validate a task update form.
 *
 * @param {object} form The form data
 * @return {{ valid: boolean, errors: object }} Validation result
 */
export function validateTaskUpdate(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('procest', 'Title is required')
	}

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
 */
export function validateTaskTransition(from, to) {
	if (!from || !to) {
		return { valid: false, error: t('procest', 'Invalid status transition') }
	}

	if (!validateTransition(from, to)) {
		if (to === 'completed' && from === 'available') {
			return {
				valid: false,
				error: t('procest', 'A task must be active before it can be completed. Start the task first.'),
			}
		}
		if (from === 'completed' || from === 'terminated' || from === 'disabled') {
			return {
				valid: false,
				error: t('procest', 'Cannot change status of a {status} task. Terminal states cannot be reversed.', { status: from }),
			}
		}
		return {
			valid: false,
			error: t('procest', 'Cannot transition from \'{from}\' to \'{to}\'', { from, to }),
		}
	}

	return { valid: true, error: null }
}
