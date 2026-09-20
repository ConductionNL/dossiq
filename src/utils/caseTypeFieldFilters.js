/**
 * Filtering the case list on a case type's own fields.
 *
 * A handler working permits does not search for a word. They search for a
 * permit whose declared construction cost is over a hundred thousand euro, or
 * for the objections whose hearing date falls this month. Those values are not
 * on the case: dossiq stores each one as a `caseProperty` row pointing back at
 * it, and the Cases page had no way to ask about them. A handler exported the
 * list and filtered it in a spreadsheet.
 *
 * 🔴 THE GRAMMAR IS MEASURED, NOT GUESSED, because a filter built against a
 * guessed grammar returns the UNFILTERED SET and reads as a working page.
 * Read against openregister `parity/round2`,
 * `lib/Service/Query/RelatedRowFilterParser.php`:
 *
 *     _related[caseProperty][case][propertyDefinition]=pd-7
 *     _related[caseProperty][case][value][gte]=100
 *
 * - `_related[<schema>][<fkProperty>][…]` names the related schema and the
 *   property on it that points back at the row being searched.
 * - ONE BLOCK IS ONE EXISTENCE CLAUSE: one `caseProperty` row that is both the
 *   named definition AND satisfies the named value test.
 * - TWO CONDITIONS ARE TWO NUMBERED BLOCKS, and the number sits after the
 *   foreign key:
 *
 *       _related[caseProperty][case][0][propertyDefinition]=pd-7
 *       _related[caseProperty][case][1][propertyDefinition]=pd-9
 *
 *   Merging them into one block asks for a single row that is two property
 *   definitions at once, and no row is, so the handler gets an empty list and
 *   no reason for it.
 * - A malformed block is REFUSED with a sentence rather than dropped. That
 *   matters here more than anywhere: a dropped filter answers every case in
 *   the register, presented as the answer to a narrow question.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */

/** The related schema a case's declared field values live in. */
export const RELATED_SCHEMA = 'caseProperty'

/** The property on that schema that points back at the case. */
export const RELATED_BACKREF = 'case'

/** The control a filled field is asked for with. */
export const CONTROL_RANGE = 'range'

/** A pair of dates. */
export const CONTROL_DATE_RANGE = 'dateRange'

/** A list the definition itself declares. */
export const CONTROL_SELECT = 'select'

/** Everything else. */
export const CONTROL_TEXT = 'text'

/**
 * The property types that are a number, and are therefore asked for as a range.
 *
 * @type {Array<string>}
 */
const NUMERIC_TYPES = ['number', 'integer']

/**
 * The property types that are a date, whatever the vocabulary calls them.
 *
 * `date` is a legacy alias for `string` with `format: date`, and both spellings
 * are stored on live instances, so both are read here. A definition whose type
 * is `string` and whose format is `date` is a date to a reader, and offering it
 * a text box would be the one place this file made a field harder to use than
 * it was.
 *
 * @type {Array<string>}
 */
const DATE_TYPES = ['date', 'datetime', 'date-time']

/**
 * The definitions a case type offers as filters.
 *
 * A definition that declares nothing is NOT offered. That is the whole point
 * of the declaration: a case type gains no filter bar by accident, and one
 * with forty attributes does not offer forty.
 *
 * @param {Array<object>} [definitions] The case type's property definitions.
 *
 * @return {Array<object>} The filterable ones, in the order they were given.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function filterableDefinitions(definitions) {
	return (Array.isArray(definitions) ? definitions : []).filter(
		(definition) => definition && definition.filterable === true,
	)
}

/**
 * The control a definition is asked for with, from its own declared type.
 *
 * The vocabulary is `39-search-declarations.json`'s: a number is a range, a
 * date is a date range, an enumeration backed by `enumValues` is a select, and
 * everything else is text.
 *
 * @param {object} [definition] The property definition.
 *
 * @return {string} One of the CONTROL_* constants.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function controlFor(definition) {
	const type = String(definition?.propertyType || '')
	const format = String(definition?.format || '')
	const values = definition?.enumValues

	if (Array.isArray(values) && values.length > 0) {
		return CONTROL_SELECT
	}

	if (NUMERIC_TYPES.includes(type)) {
		return CONTROL_RANGE
	}

	if (DATE_TYPES.includes(type) || DATE_TYPES.includes(format)) {
		return CONTROL_DATE_RANGE
	}

	return CONTROL_TEXT
}

/**
 * The id a definition is addressed by in a filter.
 *
 * @param {object} [definition] The property definition.
 *
 * @return {string} The id, or an empty string.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function definitionId(definition) {
	const self = definition?.['@self']

	return String(self?.uuid || self?.id || definition?.id || '')
}

/**
 * Whether one field's entered value asks anything at all.
 *
 * A blank field is not a filter. Sending it as an empty value would either be
 * refused or, worse, match every row that has the definition at all.
 *
 * @param {object} [value] The entered value for one field.
 *
 * @return {boolean} True when the field asks something.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function isFilled(value) {
	if (value === undefined || value === null) {
		return false
	}

	return ['eq', 'gte', 'lte', 'in'].some((key) => {
		const entry = value[key]
		if (Array.isArray(entry)) {
			return entry.length > 0
		}

		return entry !== undefined && entry !== null && String(entry) !== ''
	})
}

/**
 * Compile the bar's state into one `_related` block per filled field.
 *
 * 🔴 ONE BLOCK PER FIELD, NUMBERED. Two conditions in one block ask for a
 * single `caseProperty` row that is two property definitions, and no row is
 * that, so the handler would get an empty list and no reason for it. The
 * block index is only added when there is more than one, because a single
 * unnumbered block is the shape the parser documents.
 *
 * 🔴 THE INDEX SITS AFTER THE FOREIGN KEY, NOT AFTER THE SCHEMA. It is
 * `_related[caseProperty][case][0][propertyDefinition]`, which is what
 * `RelatedRowFilterParser::blocks()` looks for: it reads the schema, then the
 * foreign key, and only then counts numbered rows. Written the other way round
 * as `_related[caseProperty][0][case][…]`, the parser reads `0` as the FOREIGN
 * KEY and `case` as a condition, then refuses the whole query with "uses
 * operator 'propertyDefinition'". Measured against openregister `development`
 * on 2026-09-20, where the parser is merged.
 *
 * @param {Array<object>} [entries] `[{ definitionId, value }]`, in bar order.
 *
 * @return {object} The query keys, ready to merge into the route query.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function buildRelatedFilters(entries) {
	const filled = (Array.isArray(entries) ? entries : []).filter(
		(entry) => entry && entry.definitionId && isFilled(entry.value),
	)

	if (filled.length === 0) {
		return {}
	}

	const query = {}
	filled.forEach((entry, index) => {
		const block = filled.length === 1 ? '' : `[${index}]`
		const prefix = `_related[${RELATED_SCHEMA}][${RELATED_BACKREF}]${block}`

		query[`${prefix}[propertyDefinition]`] = entry.definitionId

		const value = entry.value
		if (Array.isArray(value.in) && value.in.length > 0) {
			query[`${prefix}[value][in]`] = value.in.join(',')
		}

		;['eq', 'gte', 'lte'].forEach((operator) => {
			const operand = value[operator]
			if (
				operand === undefined
				|| operand === null
				|| String(operand) === ''
			) {
				return
			}

			const suffix = operator === 'eq' ? '' : `[${operator}]`
			query[`${prefix}[value]${suffix}`] = String(operand)
		})
	})

	return query
}

/**
 * The `_related` keys a query already carries, so clearing the case type can
 * clear them with it.
 *
 * @param {object} [query] A route query.
 *
 * @return {Array<string>} The keys.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function relatedKeysIn(query) {
	return Object.keys(query || {}).filter((key) => key.startsWith('_related['))
}

/**
 * The field a refused `_related` block is about, named for the handler.
 *
 * An empty list and a refused query look the same on screen, and only one of
 * them is an answer. openregister refuses a malformed block by id, so the id
 * is translated back into the field's own name before it reaches a reader:
 * "pd-7 is not a valid filter" is not a sentence a handler can act on.
 *
 * @param {object} [options] The inputs.
 * @param {object} [options.error] Whatever the object store recorded.
 * @param {Array}  [options.definitions] The case type's definitions.
 *
 * @return {object|null} `{ name, message }`, or null when nothing was refused.
 * @spec openspec/changes/case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
export function readRelatedRefusal({ error, definitions } = {}) {
	if (!error) {
		return null
	}

	const message = String(
		error?.response?.data?.error
			|| error?.response?.data?.message
			|| error?.message
			|| '',
	)

	if (message === '' || message.includes('_related') === false) {
		return null
	}

	const named = (Array.isArray(definitions) ? definitions : []).find(
		(definition) => {
			const id = definitionId(definition)

			return id !== '' && message.includes(id)
		},
	)

	return {
		name: String(named?.name || ''),
		message,
	}
}
