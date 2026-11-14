// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Turning what the template library answered into options a picker can render.

/**
 * One content template as an option for the library's CnTemplateSelect.
 *
 * 🔑 A ROW WITHOUT AN ID IS NO OPTION, NOT A CRASH AND NOT A BLANK ROW. Every
 * option also carries a `presets` object even when the template has none, so
 * the surface that mounts the select can spread it without checking. A
 * malformed or failed answer is the select's to handle: it offers nothing.
 *
 * @param {object} item One row of GET /api/content-templates/{kind}.
 * @return {object|null} The option with id, name, body and presets, or null.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export function contentTemplateOption(item) {
	if (!item || !item.id) {
		return null
	}

	return {
		id: String(item.id),
		name: String(item.name || item.id),
		body: String(item.body || ''),
		presets:
			item.presets && typeof item.presets === 'object' ? item.presets : {},
	}
}

export default { contentTemplateOption }
