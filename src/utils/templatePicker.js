// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Turning what the template library answered into options a picker can render.

/**
 * The options for one kind of template.
 *
 * 🔑 A MALFORMED ANSWER IS AN EMPTY OFFER, NOT A CRASH AND NOT A HALF-LIST. The
 * library answers 503 when the register is unreachable, and a caller that read
 * `data.items` off that body would map over undefined. Every option also
 * carries a `presets` object even when the template has none, so the surface
 * that mounts the picker can spread it without checking.
 *
 * @param {object} data The body of GET /api/content-templates/{kind}.
 * @return {Array} The options, each with id, name, body and presets.
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
export function templateOptions(data) {
	const items = data && Array.isArray(data.items) ? data.items : []

	return items
		.filter((item) => item && item.id)
		.map((item) => ({
			id: String(item.id),
			name: String(item.name || item.id),
			body: String(item.body || ''),
			presets: item.presets && typeof item.presets === 'object' ? item.presets : {},
		}))
}

export default { templateOptions }
