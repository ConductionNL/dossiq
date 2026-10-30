// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-formatter registry for dossiq's manifest-driven index pages.
//
// Each entry is `(value, row, property) => string|number` — pure data
// shaping, referenced by id from `pages[].config.columns[].formatter`
// in src/manifest.json (resolved by CnDataTable / CnCellRenderer via the
// `formatters` prop passed to CnAppRoot, see @conduction/nextcloud-vue →
// docs/migrating-to-manifest.md "Column formatters"). Keep this file to
// pure functions — the Vue layer stays the library's abstract
// CnIndexPage / CnDataTable; only the app-specific per-row logic lives
// here. (`mapFormatters.js` is the separate registry for `type:"map"`
// marker formatting.)

import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { reactive } from 'vue'
import { useDeelzaakStore } from '../store/modules/deelzaak.js'
import { useObjectStore } from '../store/modules/object.js'
import { subCaseCountBadge } from '../utils/deelzaakHelpers.js'
import { formatDuration, parseDuration } from '../utils/durationHelpers.js'
import { fieldFill, fieldInCaseList, fieldKind, fieldOrigin, fieldTitle } from '../utils/fieldDescription.js'
import { portalName } from '../utils/portalName.js'
import { approvalMarkerLabel } from './approvalMarker.js'
import { caseAddress } from './caseAddress.js'
import { caseAnswerText } from './caseAnswer.js'
import { coveredForLabel } from './coveredFor.js'
import { scanVerdictLabel } from './scanVerdict.js'



// Batch-fetch guard for sub-case counts: collect the UUIDs requested during a
// render frame and flush them in a single round-trip on the next microtask,
// so a 25-row case list fires ONE /api/deelzaken/counts request (REQ-DZS-005-C)
// rather than 25. Each batch resolves into the deelzaak store's reactive
// subCaseCounts map, re-rendering the badge cells once it lands.
const pendingCountIds = new Set()
let countFlushScheduled = false


/**
 * An ISO duration's parts, with a number of days that is a whole number of
 * weeks (and nothing else) counted in weeks.
 *
 * @param {string} iso The ISO duration.
 * @return {{years: number, months: number, weeks: number, days: number}|null} The parts, or null when it is not one.
 */
function inWholeWeeks(iso) {
	const parts = parseDuration(iso)
	if (!parts) {
		return null
	}
	if (!parts.years && !parts.months && !parts.weeks && parts.days > 0 && parts.days % 7 === 0) {
		return { years: 0, months: 0, weeks: parts.days / 7, days: 0 }
	}
	return parts
}

/**
 * The one unit a duration is counted in, or null when it uses several.
 *
 * @param {{years: number, months: number, weeks: number, days: number}} parts The parts.
 * @return {{unit: string, count: number}|null} The unit and its count.
 */
function singleUnit(parts) {
	const used = ['years', 'months', 'weeks', 'days'].filter((unit) => parts[unit] > 0)
	return used.length === 1 ? { unit: used[0], count: parts[used[0]] } : null
}

/**
 * Duration parts back as an ISO duration.
 *
 * @param {{years: number, months: number, weeks: number, days: number}} parts The parts.
 * @return {string} The ISO duration.
 */
function toIsoDuration(parts) {
	return 'P'
		+ (parts.years ? `${parts.years}Y` : '')
		+ (parts.months ? `${parts.months}M` : '')
		+ (parts.weeks ? `${parts.weeks}W` : '')
		+ (parts.days ? `${parts.days}D` : '')
}
/**
 * Queue a parent UUID for the next batch sub-case-count fetch and schedule
 * the flush. No-ops when the count is already cached in the store.
 *
 * @param {object} store The deelzaak pinia store.
 * @param {string} uuid The parent case UUID to count children for.
 * @return {void}
 */
function queueSubCaseCount(store, uuid) {
	if (uuid in store.subCaseCounts) {
		return
	}
	pendingCountIds.add(uuid)
	if (countFlushScheduled) {
		return
	}
	countFlushScheduled = true
	Promise.resolve().then(() => {
		countFlushScheduled = false
		const ids = [...pendingCountIds]
		pendingCountIds.clear()
		if (ids.length === 0) {
			return
		}
		store.fetchSubCaseCounts(ids).catch(() => {})
	})
}

// Labels of related objects by type, filled by a lookup of their own. The
// store's shared collection for a type is the state of whatever list last
// fetched it (a filtered picker, or a query a newer one replaced), so a label
// read only from there stays a raw UUID.
const relatedLabels = reactive({})
const relatedFetchStarted = {}

/**
 * Resolve a related object's UUID to its human label: from the store's
 * collection for `type` when it holds the row, else from this module's own
 * reactive label map, which one unfiltered lookup per type fills. The
 * rendering component tracks the reactive read, so the cell re-renders with
 * the label once it arrives.
 *
 * @param {string} type Registered object type ('caseType' / 'statusType').
 * @param {string} uuid The related object's UUID.
 * @return {string} The label, or the raw UUID while unresolved.
 */
function lookupRelatedName(type, uuid) {
	if (!uuid) return '-'
	let store
	try {
		store = useObjectStore()
	} catch {
		return uuid
	}
	const collection = store.collections[type]
	const hit = (collection || []).find(
		(o) => o.id === uuid || (o['@self'] && o['@self'].id === uuid),
	)
	if (hit && (hit.title || hit.name)) {
		return hit.title || hit.name
	}
	const known = relatedLabels[type] && relatedLabels[type][uuid]
	if (known) {
		return known
	}
	if (!relatedFetchStarted[type] && typeof store.fetchCollectionForOptions === 'function' && store.objectTypeRegistry && store.objectTypeRegistry[type]) {
		relatedFetchStarted[type] = true
		Promise.resolve(store.fetchCollectionForOptions(type, { _limit: 500 }))
			.then((rows) => {
				const labels = {}
				for (const row of Array.isArray(rows) ? rows : []) {
					const id = row && (row.id || (row['@self'] && row['@self'].id))
					const label = row && (row.title || row.name)
					if (id && label) {
						labels[id] = label
					}
				}
				relatedLabels[type] = labels
				if (Object.keys(labels).length === 0) {
					relatedFetchStarted[type] = false
				}
			})
			.catch(() => {
				relatedFetchStarted[type] = false
			})
	}
	return uuid
}

/**
 * Forget the related labels (tests).
 *
 * @return {void}
 */
export function resetRelatedLabels() {
	for (const key of Object.keys(relatedLabels)) {
		delete relatedLabels[key]
	}
	for (const key of Object.keys(relatedFetchStarted)) {
		delete relatedFetchStarted[key]
	}
}

/**
 * A day as "7 Aug" in the reader's locale, or '' when it is not a day.
 *
 * @param {unknown} value The day (YYYY-MM-DD).
 * @return {string}
 */
function shortDay(value) {
	const match = typeof value === 'string' ? value.match(/^(\d{4})-(\d{2})-(\d{2})/) : null
	if (!match) {
		return ''
	}
	const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
	return new Intl.DateTimeFormat(getCanonicalLocale(), { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(date)
}

export default {
	/**
	 * A stored code by its label (`formatterOptions.labels`), translated; the
	 * code itself when it has no label (DqZaakDocumenten verdict pill).
	 *
	 * @param {unknown} value The stored code.
	 * @param {object} row The row.
	 * @param {object} property The property.
	 * @param {{labels?: object}} options The column's formatter options.
	 * @return {string}
	 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-a-document-is-assessed-from-its-row
	 */
	optionLabel: (value, row, property, options) => {
		const code = value === null || value === undefined ? '' : String(value)
		const label = options && options.labels && typeof options.labels[code] === 'string' ? options.labels[code] : ''
		return label === '' ? code : t('dossiq', label)
	},

	/**
	 * "Received 7 Aug": the day a notice came in, as the deadline dashboard
	 * names it (DqTermijnen "Ontvangen 7 aug"); empty without a day.
	 *
	 * @param {unknown} value The day (YYYY-MM-DD).
	 * @return {string}
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	receivedOn: (value) => {
		const day = shortDay(value)
		return day === '' ? '' : t('dossiq', 'Received {date}', { date: day })
	},

	/**
	 * A notice's penalty in words: "€ 1.442, fixed", "€ 207, running since
	 * 16 Oct" or "None" (DqTermijnen "Dwangsom").
	 *
	 * @param {unknown} value The penalty state: `definitive`, `running` or `none`.
	 * @param {object} row The row, with `penaltyEuros` and `penaltySince`.
	 * @return {string}
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	penaltyLine: (value, row) => {
		if (value !== 'definitive' && value !== 'running') {
			return t('dossiq', 'None')
		}
		const amount = new Intl.NumberFormat(getCanonicalLocale(), { style: 'currency', currency: 'EUR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(row?.penaltyEuros) || 0)
		if (value === 'definitive') {
			return t('dossiq', '{amount}, fixed', { amount })
		}
		const since = shortDay(row?.penaltySince)
		return since === '' ? amount : t('dossiq', '{amount}, running since {date}', { amount, date: since })
	},

	/**
	 * Whether a list value holds `formatterOptions.token`, as the translated
	 * `yes` or `no` word (DqTenant Mandaten "Bezwaar beslissen"). A comma
	 * separated text counts as a list.
	 *
	 * @param {unknown} value A list of codes, or a comma separated text.
	 * @param {object} row The row.
	 * @param {object} property The property.
	 * @param {{token?: string, yes?: string, no?: string}} options The column's formatter options.
	 * @return {string}
	 * @spec openspec/changes/tenant-tables-in-words/specs/tenant-tables-in-words/spec.md#requirement-a-list-value-is-read-as-yes-or-no-by-a-token-it-holds
	 */
	containsLabel: (value, row, property, options) => {
		const opts = options || {}
		const list = Array.isArray(value) ? value : String(value ?? '').split(',')
		const holds = list.some((entry) => String(entry).trim() === String(opts.token ?? ''))
		return t('dossiq', holds ? (opts.yes || 'Yes') : (opts.no || 'No'))
	},

	/**
	 * The outcome of a document checksum's last check, in words; the code
	 * itself when it is not one of the four.
	 *
	 * @param {unknown} value `unchanged`, `changed`, `missing` or `unchecked`.
	 * @return {string}
	 * @spec openspec/specs/document-integrity-check/spec.md#requirement-the-checksum-card-says-when-and-how-the-file-was-last-checked
	 */
	integrityStatus: (value) => {
		const labels = {
			unchanged: t('dossiq', 'Unchanged'),
			changed: t('dossiq', 'Changed since it was stored'),
			missing: t('dossiq', 'File not found'),
			unchecked: t('dossiq', 'Too large to check'),
		}
		const code = value === null || value === undefined ? '' : String(value)
		return labels[code] ?? code
	},

	/**
	 * Whom a covered case is covered for, and until when ("For Anouk Bakker,
	 * until 10 Oct"); '' on the reader's own case.
	 *
	 * @param {object|null} value The case's `coveredFor`.
	 * @return {string}
	 */
	coveredFor: (value) => coveredForLabel(value),

	/**
	 * Human label for a case's `caseType` UUID reference.
	 *
	 * @param {string} value The caseType UUID.
	 * @return {string}
	 */
	caseTypeName: (value) => lookupRelatedName('caseType', value),

	/**
	 * A case's address, from its first `case-location` ('' while it loads or
	 * when the case has none), for a list line such as the map list's.
	 *
	 * @param {string} value The case id.
	 * @return {string}
	 * @spec openspec/specs/zakenkaart-map-list/spec.md#requirement-a-map-list-row-reads-address-case-number-and-names-its-type-and-status
	 */
	caseAddress: (value) => caseAddress(value),

	/**
	 * A number of case types as a phrase ("3 case types"); '' when the count
	 * is unknown (null) or not a count.
	 *
	 * @param {unknown} value The count.
	 * @return {string} The phrase, or ''.
	 * @spec openspec/changes/object-types-used-in-case-types/specs/catalogue/spec.md
	 */
	caseTypeCount: (value) => {
		if (value === null || value === undefined || value === '') {
			return ''
		}
		const count = Number(value)
		return Number.isInteger(count) && count >= 0 ? n('dossiq', '%n case type', '%n case types', count) : ''
	},

	/**
	 * A number of cases as a phrase ("2 cases").
	 *
	 * @param {unknown} value The count.
	 * @return {string} The phrase, or '' when the value is not a count.
	 */
	caseCount: (value) => {
		const count = Number(value)
		return Number.isInteger(count) && count >= 0 ? n('dossiq', '%n case', '%n cases', count) : ''
	},

	/**
	 * The kind of answer a case type field holds ("Choice list, 6 options").
	 *
	 * @param {unknown} value The field's type.
	 * @param {object} row The field.
	 * @return {string} The kind.
	 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
	 */
	fieldKind: (value, row) => fieldKind(row),

	/**
	 * How a case type field is filled in (Required, Optional, Read only).
	 *
	 * @param {unknown} value Whether the field is required.
	 * @param {object} row The field.
	 * @return {string} The words.
	 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
	 */
	fieldFill: (value, row) => fieldFill(row),

	/**
	 * Where a case type field comes from ("Own field", "Inherited from …").
	 *
	 * @param {unknown} value The cell value (unused).
	 * @param {object} row The field.
	 * @return {string} The words.
	 * @spec openspec/specs/case-types/spec.md
	 */
	fieldOrigin: (value, row) => fieldOrigin(row),

	/**
	 * What a person reads for a case type field: its title, else its name.
	 *
	 * @param {unknown} value The cell value (unused).
	 * @param {object} row The field.
	 * @return {string} The words.
	 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
	 */
	fieldTitle: (value, row) => fieldTitle(row),

	/**
	 * Whether the case type on the page shows the field as a case list column.
	 *
	 * @param {unknown} value The cell value (unused).
	 * @param {object} row The field.
	 * @param {object} property The column's property (unused).
	 * @param {object} options The column's options (unused).
	 * @param {{object: (object|null)}} context The page record (nextcloud-vue cell-formatter-page-record).
	 * @return {string} Yes or No.
	 * @spec openspec/specs/case-types/spec.md
	 */
	fieldInCaseList: (value, row, property, options, context) => fieldInCaseList(row, context && context.object),

	/**
	 * A schema's number of properties as a phrase ("9 properties"), empty
	 * when the object type names no schema.
	 *
	 * @param {unknown} value The count.
	 * @return {string} The phrase, or ''.
	 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
	 */
	propertyCount: (value) => {
		if (value === null || value === undefined || value === '') {
			return ''
		}
		const count = Number(value)
		return Number.isInteger(count) && count >= 0 ? n('dossiq', '%n property', '%n properties', count) : ''
	},

	/**
	 * The views an object type supports, in words ("Map, Table").
	 *
	 * @param {unknown} value The view keys, `map` and `table`.
	 * @return {string} The views, or '' for none.
	 * @spec openspec/changes/screens-round15-catalogue-schema-columns/specs/catalogue/spec.md
	 */
	objectViews: (value) => {
		const labels = { map: t('dossiq', 'Map'), table: t('dossiq', 'Table') }
		return (Array.isArray(value) ? value : []).map((view) => labels[view]).filter(Boolean).join(', ')
	},

	/**
	 * Human label for a case object's `case` UUID reference.
	 *
	 * The Objects index answers "which cases are on this building", so the
	 * case is the column a reader looks at. The reference is NOT resolved
	 * with `extend` the way the Tasks index resolves its own `case` column:
	 * `extend` replaces `row.case` with the expanded case object, and the
	 * page's View case action resolves its `{case}` token with a flat row
	 * lookup, so it would push an object where vue-router wants a uuid and
	 * open nothing. The formatter leaves the uuid on the row and resolves
	 * only what is rendered. An id the case collection does not hold falls
	 * back to the id rather than to an empty cell.
	 *
	 * @param {string} value The case UUID.
	 * @return {string}
	 * @spec openspec/specs/case-management/spec.md
	 */
	caseTitle: (value) => lookupRelatedName('case', value),

	/**
	 * Who asked for a case, by name.
	 *
	 * Read off the case's `initiatorDisplayName`: the picker writes it beside
	 * the `requester` row it picked, and a case filed by a resident on the
	 * portal carries the name they gave, with no register row to point at.
	 * Empty when the case names nobody.
	 *
	 * @param {string} value The `requester` uuid, unused.
	 * @param {object} row The case.
	 * @return {string}
	 * @spec openspec/changes/a-portal-request-names-its-requester/specs/woo-request-intake/spec.md#requirement-a-case-filed-for-a-resident-names-who-asked-req-wri-006
	 */
	requesterName: (value, row) => String(row?.initiatorDisplayName ?? '').trim(),

	/**
	 * An ISO 8601 duration as words ("P56D" reads "8 weeks"), and the value
	 * itself when it is not one.
	 *
	 * @param {string} value The ISO duration.
	 * @return {string} The words, or the value unchanged.
	 */
	isoDuration: (value) => formatDuration(value),

	/**
	 * A case type's processing time with the extension it allows, as
	 * DqZaaktypen draws it ("8 weeks, +6"). A whole number of weeks in days
	 * reads as weeks, and an extension in the same unit as the term is a bare
	 * number. The extension shows only when the case type allows one and
	 * names its length.
	 *
	 * @param {string} value The ISO processing deadline.
	 * @param {object} [row] The case type row.
	 * @return {string} The words, or the value unchanged.
	 * @spec openspec/specs/case-types/spec.md
	 */
	processingTime: (value, row) => {
		const base = inWholeWeeks(value)
		const time = base ? formatDuration(toIsoDuration(base)) : formatDuration(value)
		const extension = row?.extensionAllowed === false ? '' : row?.extensionPeriod
		if (!time || !extension) {
			return time
		}
		const more = inWholeWeeks(extension)
		if (!more) {
			return t('dossiq', '{time}, +{extension}', { time, extension: formatDuration(extension) })
		}
		const baseUnit = base ? singleUnit(base) : null
		const moreUnit = singleUnit(more)
		const sameUnit = baseUnit && moreUnit && baseUnit.unit === moreUnit.unit
		return t('dossiq', '{time}, +{extension}', {
			time,
			extension: sameUnit ? String(moreUnit.count) : formatDuration(toIsoDuration(more)),
		})
	},

	/**
	 * A version number as words ("1" reads "Version 1"). A manifest cannot
	 * carry the phrase itself: the library's conditionalPhrase takes it
	 * pre-translated, and a manifest string is not translated.
	 *
	 * @param {number|string|null|undefined} value The version number.
	 * @return {string} The phrase, or '' when there is no version.
	 */
	versionLabel: (value) =>
		value === null || value === undefined || value === ''
			? ''
			: t('dossiq', 'Version {n}', { n: value }),

	/**
	 * Whether a record is still a draft, as the word a status pill shows.
	 * A record with no `isDraft` is not a draft.
	 *
	 * @param {boolean|null|undefined} value The `isDraft` flag.
	 * @return {string} Draft or Published.
	 */
	draftState: (value) =>
		value === true ? t('dossiq', 'Draft') : t('dossiq', 'Published'),

	/**
	 * Human label for a case's `status` UUID reference (statusType).
	 *
	 * @param {string} value The statusType UUID.
	 * @return {string}
	 */
	statusTypeName: (value) => lookupRelatedName('statusType', value),

	/**
	 * A case's priority as a phrase for a list line ("prioriteit hoog",
	 * DqTeamwachtrij); '' for an empty or unknown value.
	 *
	 * @param {string} value The stored priority (low, normal, high, urgent).
	 * @return {string}
	 */
	priorityPhrase: (value) => {
		const phrases = {
			low: t('dossiq', 'priority low'),
			normal: t('dossiq', 'priority normal'),
			high: t('dossiq', 'priority high'),
			urgent: t('dossiq', 'priority urgent'),
		}
		return phrases[value] ?? ''
	},

	/**
	 * One case-type field of a case, named by the column's
	 * `formatterOptions.name`, read off the case's `properties`.
	 *
	 * @param {Array<object>|null} value The case's `properties`.
	 * @param {object} row The case.
	 * @param {object} property The column's property.
	 * @param {{name: string}} options The column's `formatterOptions`.
	 * @return {string} The answer as text.
	 * @spec openspec/changes/re-integration-is-a-case-type/specs/dossiq-sociaal-domein-participatiewet/spec.md
	 */
	caseAnswer: (value, row, property, options) => caseAnswerText(value, options),

	/**
	 * What the virus scanner recorded about a document row.
	 *
	 * Read, never inferred: the value is whatever `ScanVerdictReader`
	 * answered, and a row carrying nothing reads Not scanned rather than
	 * blank, because a blank cell in a Scan column reads as reassurance.
	 *
	 * @param {object|string} value The verdict, or the bare state.
	 * @return {string} The sentence.
	 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
	 */
	scanVerdict: (value) => scanVerdictLabel(value),
	// approval-chain-on-the-document REQ-BVL-005. The value is the marker the
	// approval-markers endpoint answered for this document, or undefined for a
	// document nobody routed, which renders NOTHING rather than a word: an
	// empty marker and "Approved" are different rows.
	approvalChain: (value) => approvalMarkerLabel(value),

	/**
	 * A document's direction, by its label, for the Files tab's Direction
	 * column. A row without one renders nothing.
	 *
	 * @param {string} value The direction (`incoming`, `outgoing`, `internal`).
	 * @return {string} The label.
	 * @spec openspec/changes/documents-on-the-case/tasks.md
	 */
	documentDirection: (value) => {
		const labels = {
			incoming: t('dossiq', 'Incoming'),
			outgoing: t('dossiq', 'Outgoing'),
			internal: t('dossiq', 'Internal'),
		}
		return labels[String(value ?? '')] ?? ''
	},

	/**
	 * A document's confidentiality, by its label, for the Files tab's
	 * Visible to column. The value is the stored level; a row without one
	 * renders nothing.
	 *
	 * @param {string} value The confidentiality level.
	 * @return {string} The label.
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	/**
	 * Who a document's access exception names, as the Who can see this card reads it: "Only Pieter Jansen and
	 * Woo team". The listing gives the display names, people first, then groups.
	 *
	 * @param {Array<string>} value The display names.
	 * @return {string} The sentence, or '' when the document carries no exception.
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	 */
	accessExceptionNames: (value) => {
		const names = (Array.isArray(value) ? value : []).map((name) => String(name ?? '').trim()).filter((name) => name !== '')
		if (names.length === 0) {
			return ''
		}
		if (names.length === 1) {
			return t('dossiq', 'Only {name}', { name: names[0] })
		}
		return t('dossiq', 'Only {names} and {last}', { names: names.slice(0, -1).join(', '), last: names[names.length - 1] })
	},

	confidentialityLabel: (value) => {
		const labels = {
			openbaar: t('dossiq', 'Public'),
			beperkt_openbaar: t('dossiq', 'Restricted public'),
			intern: t('dossiq', 'Internal'),
			zaakvertrouwelijk: t('dossiq', 'Case confidential'),
			vertrouwelijk: t('dossiq', 'Confidential'),
			confidentieel: t('dossiq', 'Classified confidential'),
			geheim: t('dossiq', 'Secret'),
			zeer_geheim: t('dossiq', 'Top secret'),
		}
		return labels[String(value ?? '')] ?? ''
	},

	/**
	 * The messages a case sent on its own, one line each: what it was, the
	 * channel it went through and when ("Receipt confirmation via the portal,
	 * 3 Oct 14:32"). The portal is named as portaliq names it ("Mijn
	 * Zuiddrecht"), else "the portal". A message no channel took reads
	 * "not sent".
	 *
	 * @param {Array<object>} value The case's outbound communications.
	 * @return {string} The lines joined with "; ", or '' without messages.
	 * @spec openspec/specs/receipt-confirmation-card/spec.md#requirement-outbound-messages-read-as-lines
	 */
	outboundMessages: (value) => {
		if (!Array.isArray(value)) {
			return ''
		}
		const moments = {
			acknowledgement: t('dossiq', 'Receipt confirmation'),
			'information-request': t('dossiq', 'Information request'),
			stage: t('dossiq', 'Status update'),
			extension: t('dossiq', 'Extension'),
			decision: t('dossiq', 'Decision'),
			transfer: t('dossiq', 'Transfer'),
		}
		const channels = {
			'portal-inbox': portalName() || t('dossiq', 'the portal'),
			'digital-post': t('dossiq', 'digital post'),
			email: t('dossiq', 'email'),
		}
		const when = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		return value
			.filter((entry) => entry && typeof entry === 'object')
			.map((entry) => {
				const moment = moments[entry.moment] ?? String(entry.moment ?? '')
				if (!entry.channel) {
					return t('dossiq', '{moment}, not sent', { moment })
				}
				const sentAt = entry.sentAt ? new Date(entry.sentAt) : null
				const time = sentAt && !Number.isNaN(sentAt.getTime()) ? when.format(sentAt) : ''
				const channel = channels[entry.channel] ?? entry.channel
				return time
					? t('dossiq', '{moment} via {channel}, {time}', { moment, channel, time })
					: t('dossiq', '{moment} via {channel}', { moment, channel })
			})
			.join('; ')
	},

	/**
	 * Sub-case count badge for a case row in the case list. Returns "N
	 * deelzaken" for cases with one or more sub-cases and an empty string
	 * (no badge) otherwise. The count is read from the reactive deelzaak
	 * store; on the first render for an uncounted case it queues a batched
	 * /api/deelzaken/counts fetch and re-renders once the count lands.
	 *
	 * @param {unknown} value Unused (the column key is the case UUID via `row`).
	 * @param {object} row The case object.
	 * @return {string} Badge label, or '' when the case has no sub-cases.
	 * @spec openspec/changes/deelzaak-support/tasks.md#T10
	 */
	subCaseCount: (value, row) => {
		const uuid = (row && (row.id || (row['@self'] && row['@self'].id))) || value
		if (!uuid) {
			return ''
		}
		let store
		try {
			store = useDeelzaakStore()
		} catch {
			return ''
		}
		// Sub-cases themselves never carry sub-cases (zrc-013c) — skip the count.
		if (row && row.parentCase) {
			return ''
		}
		queueSubCaseCount(store, uuid)
		return subCaseCountBadge(store.subCaseCounts[uuid] || 0)
	},
}
