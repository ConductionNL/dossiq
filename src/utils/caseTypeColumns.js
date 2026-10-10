/**
 * The columns the admin case type list shows, and in which order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * 🔴 THE CASE TYPE SCHEMA HAS 96 PROPERTIES. Handed to the table as-is, the
 * library draws a column for every scalar property, sorted alphabetically
 * when no `order` is declared, so the title sat far off-screen behind columns
 * that were all "—" (round-4 cloud check). The list names its own columns,
 * and gives them an order, because `includeColumns` only filters: the library
 * sorts what is left by `order` and then by key.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */

/** The columns, in reading order. Title first. */
export const CASE_TYPE_COLUMNS = Object.freeze([
	'title',
	'identifier',
	'isDraft',
	'processingDeadline',
	'validFrom',
])

/**
 * A copy of the schema whose listed properties carry their position as `order`.
 *
 * The schema itself is left alone: the object store caches it, and the
 * detail form reads the same object.
 *
 * @param {object|null} schema The case type schema.
 * @param {Array<string>} columns The columns, in reading order.
 * @return {object|null} The schema copy, or null when there is no schema.
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
export function orderedListSchema(schema, columns = CASE_TYPE_COLUMNS) {
	if (!schema || typeof schema !== 'object' || !schema.properties) {
		return schema ?? null
	}

	const properties = { ...schema.properties }
	columns.forEach((key, index) => {
		if (properties[key]) {
			properties[key] = { ...properties[key], order: index + 1 }
		}
	})

	return { ...schema, properties }
}
