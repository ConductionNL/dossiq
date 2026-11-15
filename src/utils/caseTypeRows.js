/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The first hundred objects of one schema that belong to a case type, or an
 * empty list when the store returns nothing.
 *
 * @param {object} objectStore The object store.
 * @param {string} schema The schema, such as `statusType`.
 * @param {string} caseTypeId The case type.
 * @return {Promise<Array<object>>} The rows.
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
export async function caseTypeRows(objectStore, schema, caseTypeId) {
	return (
		(await objectStore.fetchCollection(schema, {
			caseType: caseTypeId,
			_limit: 100,
		})) || []
	)
}

/**
 * The status, role and document types of a case type, in that order.
 *
 * @param {object} objectStore The object store.
 * @param {string} caseTypeId The case type.
 * @return {Promise<Array<Array<object>>>} The three lists.
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
export async function caseTypeParts(objectStore, caseTypeId) {
	const statusTypes = await caseTypeRows(objectStore, 'statusType', caseTypeId)
	const roleTypes = await caseTypeRows(objectStore, 'roleType', caseTypeId)
	const docTypes = await caseTypeRows(objectStore, 'documentType', caseTypeId)
	return [statusTypes, roleTypes, docTypes]
}
