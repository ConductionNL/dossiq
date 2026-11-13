// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The engine route and body that assign a task to the chosen performer.
 *
 * @param {string} taskId The task uuid.
 * @param {string} kind `user` or `group`.
 * @param {string} target The uid or the group id.
 * @return {{url: string, body: object}}
 * @spec openspec/changes/capability-rows-say-the-whole-capability/tasks.md
 */
export function assignRequest(taskId, kind, target) {
	const base = `/apps/openregister/api/flow-tasks/${encodeURIComponent(taskId)}`
	if (kind === 'group') {
		return { url: `${base}/offer`, body: { candidateGroups: [target] } }
	}
	return { url: `${base}/assign`, body: { assignee: target } }
}
