// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// What a new instance starts with: the shipped configuration, the municipal
// role set, retirement, the domain copy and the reusable steps. Every endpoint
// is documented in lib/Controller/StarterContentController.php and routed by
// appinfo/routes.php (the "starter" block).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/dossiq' + path)

/**
 * Read what shipped for one schema, and what was changed here.
 *
 * @param {string} schema The schema slug, for example caseType.
 * @return {Promise<object>} The rows, each with its state and set version.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function listShipped(schema) {
	const { data } = await axios.get(base('/api/starter/shipped/' + schema))
	return data
}

/**
 * Take the newer shipped version of one object.
 *
 * @param {string} schema The schema slug.
 * @param {string} id The object's id.
 * @param {object} payload The set, the new object and whether a local change may go.
 * @return {Promise<object>} Whether it was adopted, and why not when it was not.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function adoptShipped(schema, id, payload) {
	const { data } = await axios.post(base('/api/starter/shipped/' + schema + '/' + id + '/adopt'), payload)
	return data
}

/**
 * Read the shipped municipal role set and whether it is in use.
 *
 * @return {Promise<object>} The offer.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function readRoleSet() {
	const { data } = await axios.get(base('/api/starter/roles'))
	return data
}

/**
 * Take the shipped role set into use.
 *
 * @return {Promise<object>} What happened.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function adoptRoleSet() {
	const { data } = await axios.post(base('/api/starter/roles/adopt'))
	return data
}

/**
 * Put the shipped role set back to dormant.
 *
 * @return {Promise<object>} What happened, and which role stopped it.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function undoRoleSet() {
	const { data } = await axios.post(base('/api/starter/roles/undo'))
	return data
}

/**
 * Stop a case type taking new cases.
 *
 * @param {string} caseTypeId The case type's id.
 * @return {Promise<object>} What happened, and the state it is in now.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function retireCaseType(caseTypeId) {
	const { data } = await axios.post(base('/api/starter/case-types/' + caseTypeId + '/retire'))
	return data
}

/**
 * Offer a retired case type again.
 *
 * @param {string} caseTypeId The case type's id.
 * @return {Promise<object>} What happened, and the state it is in now.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function restoreCaseType(caseTypeId) {
	const { data } = await axios.post(base('/api/starter/case-types/' + caseTypeId + '/restore'))
	return data
}

/**
 * Stand a new domain up from an existing one.
 *
 * @param {string} domainId The domain to copy.
 * @param {string} name What the new domain is called.
 * @return {Promise<object>} What came along, and what did not.
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
export async function copyDomain(domainId, name) {
	const { data } = await axios.post(base('/api/starter/domains/' + domainId + '/copy'), { name })
	return data
}

/**
 * The case types that use one reusable step.
 *
 * @param {string} stepId The step's id.
 * @return {Promise<object>} The case types.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export async function stepUsedBy(stepId) {
	const { data } = await axios.get(base('/api/starter/steps/' + stepId + '/used-by'))
	return data
}

export default {
	listShipped,
	adoptShipped,
	readRoleSet,
	adoptRoleSet,
	undoRoleSet,
	retireCaseType,
	restoreCaseType,
	copyDomain,
	stepUsedBy,
}
