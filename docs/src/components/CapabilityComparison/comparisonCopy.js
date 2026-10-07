/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words on the "How dossiq compares" docs page, and the sentences that
 * carry numbers.
 *
 * WHY THIS LIVES IN THE DOCS SITE. The comparison used to be a tab on the
 * in-app Features & roadmap page. Ruben ruled on 2026-10-07 that how an app
 * compares belongs on its public site and not in the app, for every app. The
 * app now links here. The DATA did not move: this module and the page read
 * `openspec/parity/capabilities.json`, the same file the tab read, through the
 * same helpers in `src/utils/capabilityComparison.js`. So there is one copy of
 * the ratings, and the docs page cannot drift from it.
 *
 * The English strings are the keys, the Nextcloud convention the tab used.
 * The Dutch strings are the reviewed translations the tab shipped in
 * `l10n/nl.json`, moved here when the tab left the app. The sentence builders
 * are the tab's computed properties, ported one to one, so every number on
 * the page is still derived from the rows rather than written down.
 *
 * Pure functions, no React: tests/vitest/capabilityComparisonCopy.spec.js
 * runs them in node.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */

import { formatComparedOn, pendingRows } from '../../../../src/utils/capabilityComparison.js'

/**
 * Dutch for every string on the page, keyed by its English source.
 *
 * @type {Readonly<Record<string, string>>}
 */
export const NL = Object.freeze({
	"Before you use this table":
		"Voordat je deze tabel gebruikt",
	"We only compared open source software we could install and run ourselves. Closed and hosted products are not in this table. Their absence is not a verdict on them.":
		"We vergeleken alleen open source software die we zelf konden installeren en draaien. Gesloten en gehoste producten staan niet in deze tabel. Dat ze ontbreken is geen oordeel over die producten.",
	"A rating is our reading of software we did not write. It is not proof that a product does or does not have a capability.":
		"Een beoordeling is onze lezing van software die we niet zelf schreven. Het is geen bewijs dat een product een functie wel of niet heeft.",
	"We strongly advise you to run your own evaluation. This table does not replace testing against your own requirements.":
		"We raden je sterk aan zelf een evaluatie te doen. Deze tabel vervangt geen test tegen je eigen eisen.",
	"Every system here is a municipal case system or a workflow engine, and the list is drawn from what they do, in the shape we do it. A capability none of them has is missing from the list, not from the market. A product that splits the work differently scores low without being worse, and that bias runs in our favour. We add rows as we read more systems, so a lower score in a later release can mean the list grew rather than the product shrank.":
		"Elk systeem hier is een gemeentelijk zaaksysteem of een workflow-engine, en de lijst komt voort uit wat die systemen doen, in de vorm waarin wij het doen. Functionaliteit die geen van hen heeft, ontbreekt in de lijst en niet in de markt. Een product dat het werk anders verdeelt, scoort laag zonder slechter te zijn, en die vertekening valt in ons voordeel uit. We voegen rijen toe naarmate we meer systemen lezen, dus een lager cijfer in een latere release kan betekenen dat de lijst groeide en niet dat het product kromp.",
	"Totals over all {count} capabilities":
		"Totalen over alle {count} functies",
	"How many of the {count} capabilities each system has.":
		"Hoeveel van de {count} functies elk systeem heeft.",
	"System":
		"Systeem",
	"Per area":
		"Per gebied",
	"No.":
		"Nr.",
	"Capability":
		"Functie",
	"Proposed, not yet rated":
		"Voorgesteld, nog niet beoordeeld",
	"Another {count} capabilities are proposed and not yet rated. We read our own code for each of them. We have not read the other {others} systems against them, so they are in no total on this page. A proposal becomes a row when every system has been read against it.":
		"Nog eens {count} capaciteiten zijn voorgesteld en nog niet beoordeeld. Voor elk ervan hebben we onze eigen code gelezen. We hebben de andere {others} systemen er niet tegen gelezen, dus ze tellen op deze pagina in geen enkel totaal mee. Een voorstel wordt een rij zodra elk systeem ertegen is gelezen.",
	"We rated dossiq and {rivals} other case management systems on {count} capabilities: {systems}.":
		"We beoordeelden dossiq en {rivals} andere zaaksystemen op {count} functies: {systems}.",
	"Pick the capabilities your organisation needs. Then test all {count} systems against that shortlist yourself.":
		"Kies de functies die jouw organisatie nodig heeft. Test daarna zelf alle {count} systemen op die shortlist.",
	"We corrected {count} of our own ratings on {date}. Some we had shipped since the reading, and some we had read too harshly. We do not correct the other {others} columns that way. Those ratings are as we read them, on the dates given here.":
		"We hebben op {date} {count} van onze eigen beoordelingen gecorrigeerd. Sommige hadden we sinds de meting gebouwd, andere hadden we te streng beoordeeld. De andere {others} kolommen corrigeren we zo niet. Die beoordelingen zijn zoals we ze lazen, op de data die hier staan.",
	"We read all {count} systems on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.":
		"We lazen alle {count} systemen op {date}. Open source gaat snel, dus een deel van deze beoordelingen is nu al verouderd. Kijk naar die datum voordat je erop vertrouwt.",
	"We read {count} of the {total} systems on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.":
		"We lazen {count} van de {total} systemen op {date}. Open source gaat snel, dus een deel van deze beoordelingen is nu al verouderd. Kijk naar die datum voordat je erop vertrouwt.",
	"Later rounds of reading asked questions the first round had not thought of. In all we added {count} capabilities to the list, the most recent of them on {date}. We rated ourselves on every one. The other {others} columns read Unknown, because we did not read those products against these rows, and a guessed rating is worse than an empty cell.":
		"Latere leesrondes stelden vragen die de eerste ronde niet had bedacht. In totaal hebben we {count} functies aan de lijst toegevoegd, de laatste daarvan op {date}. Onszelf hebben we op elk van die rijen beoordeeld. De andere {others} kolommen staan op Onbekend, want die producten hebben we niet tegen deze rijen gelezen, en een gegokt oordeel is erger dan een lege cel.",
	"We read {system} on {date}, not on the date above. Its column joined this table on {added}.":
		"We lazen {system} op {date}, niet op de datum hierboven. De kolom kwam op {added} in deze tabel.",
	"{system} owns no data. The case, its documents and its retention live in {register}. A row we score in our own shape often falls outside {system} by design. Its column reads low for that reason, which flatters us. Read the gap as a difference in architecture, not as a weaker product.":
		"{system} bezit geen data. De zaak, de documenten en de bewaartermijn staan in {register}. Een rij die we in onze eigen vorm beoordelen valt vaak buiten {system}, met opzet. De kolom scoort daardoor laag, en dat vleit ons. Lees het verschil als architectuur, niet als een zwakker product.",
	"About the {system} column":
		"Over de kolom {system}",
	"Yes":
		"Ja",
	"Partly":
		"Deels",
	"Not found":
		"Niet gevonden",
	"Unknown":
		"Onbekend",
	"we found the whole capability":
		"we vonden de hele functie",
	"we found part of it, and part is missing":
		"we vonden een deel, een deel ontbreekt",
	"we have not read that system on this row":
		"we hebben dat systeem op deze regel niet gelezen",
	"we did not find it":
		"we hebben het niet gevonden",
	"{count} proposed, none rated yet.":
		"{count} voorgesteld, nog geen enkele beoordeeld.",
	"{total} capabilities. Dossiq has {yes}, partly has {partial}, is missing {no}. {pending} more proposed.":
		"{total} capaciteiten. Dossiq heeft er {yes}, heeft er {partial} deels, mist er {no}. Nog {pending} voorgesteld.",
	"{total} capabilities. Dossiq has {yes}, partly has {partial}, is missing {no}.":
		"{total} functies. Dossiq heeft er {yes}, heeft er {partial} deels, mist er {no}.",
	"Capabilities in {area}, rated for each of the {count} systems.":
		"Functies in {area}, beoordeeld voor elk van de {count} systemen.",
	"{count} capabilities proposed for {area}. We rated ourselves. The other {others} systems have not been read against these, so they are in no total here.":
		"{count} capaciteiten voorgesteld voor {area}. We hebben onszelf beoordeeld. De andere {others} systemen zijn hier niet tegen gelezen, dus ze tellen hier in geen enkel totaal mee.",
	'Search capabilities': 'Zoek in de functies',
	'Rating for {system}': 'Beoordeling van {system}',
	'Any rating': 'Elke beoordeling',
	'{count} of the {total} rated capabilities match.':
		'{count} van de {total} beoordeelde functies passen bij je zoekopdracht.',
	'No capability matches. Clear the search or pick another rating.':
		'Geen functie past. Wis de zoekopdracht of kies een andere beoordeling.',
})

/**
 * Translate one string and fill its `{placeholders}`.
 *
 * Dutch for a Dutch locale when the string has a Dutch entry, English
 * otherwise, so a missing entry degrades to English rather than to a blank.
 *
 * @param {string} locale Docusaurus locale, e.g. `en` or `nl`.
 * @param {string} key The English source string.
 * @param {Record<string, string|number>} [vars] Placeholder values.
 * @return {string} The translated, filled string.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
 */
export function translate(locale, key, vars = {}) {
	const dutch = String(locale || '').toLowerCase().startsWith('nl')
	const template = dutch && NL[key] ? NL[key] : key
	return template.replace(/\{(\w+)\}/g, (match, name) =>
		name in vars ? String(vars[name]) : match,
	)
}

/**
 * Whether a column was read on a day other than the comparison's own.
 *
 * @param {object} data Parsed `openspec/parity/capabilities.json`.
 * @param {object} system A `systems` entry.
 * @return {boolean} True when it carries a `readOn` that is not `comparedOn`.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */
export function isLate(data, system) {
	return Boolean(system.readOn) && system.readOn !== data.comparedOn
}

/**
 * Every sentence on the page that carries a number or a date.
 *
 * A port of the computed properties of the retired in-app tab. The comments
 * there explained each choice, and the reasons still hold. In short: every
 * count is derived from the rows, a correction is counted only when it moved
 * a rating, and a column read on its own day says so beside itself.
 *
 * @param {object} data Parsed `openspec/parity/capabilities.json`.
 * @param {string} locale Docusaurus locale.
 * @return {{leadText: string, caveats: Array<string>, systemNotes: Array<object>}} The lead, the caveats in reading order, and one note per column that needs reading differently.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */
export function comparisonCopy(data, locale) {
	const t = (key, vars) => translate(locale, key, vars)
	const date = (iso) => formatComparedOn(iso, locale)
	const systems = data.systems ?? []
	const rivals = systems.filter((system) => !system.isSelf)
	const others = systems.length - 1

	const pending = pendingRows(data)
	const pendingText = pending.length === 0
		? ''
		: t(
			'Another {count} capabilities are proposed and not yet rated. We read our own code for each of them. We have not read the other {others} systems against them, so they are in no total on this page. A proposal becomes a row when every system has been read against it.',
			{ count: pending.length, others },
		)

	const leadText = t(
		'We rated dossiq and {rivals} other case management systems on {count} capabilities: {systems}.',
		{
			rivals: rivals.length,
			count: data.capabilities.length,
			systems: rivals.map((system) => system.name).join(', '),
		},
	)

	const shortlistText = t(
		'Pick the capabilities your organisation needs. Then test all {count} systems against that shortlist yourself.',
		{ count: systems.length },
	)

	// Only entries that MOVED a rating. `_rerated` also logs added rows,
	// which carry a null `from`.
	const corrections = (data._rerated ?? []).filter((entry) => entry.from !== null)
	const reratedText = corrections.length === 0
		? ''
		: t(
			'We corrected {count} of our own ratings on {date}. Some we had shipped since the reading, and some we had read too harshly. We do not correct the other {others} columns that way. Those ratings are as we read them, on the dates given here.',
			{
				count: corrections.length,
				others,
				date: date(corrections.map((entry) => entry.on).sort().at(-1)),
			},
		)

	const onTime = systems.filter((system) => !isLate(data, system)).length
	const readingDateText = onTime === systems.length
		? t(
			'We read all {count} systems on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.',
			{ count: onTime, date: date(data.comparedOn) },
		)
		: t(
			'We read {count} of the {total} systems on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.',
			{ count: onTime, total: systems.length, date: date(data.comparedOn) },
		)

	const added = data.capabilities.filter((row) => row.addedOn)
	const addedRowsText = added.length === 0 || !data.rowsAddedOn
		? ''
		: t(
			'Later rounds of reading asked questions the first round had not thought of. In all we added {count} capabilities to the list, the most recent of them on {date}. We rated ourselves on every one. The other {others} columns read Unknown, because we did not read those products against these rows, and a guessed rating is worse than an empty cell.',
			{
				count: added.length,
				others,
				date: date(added.map((row) => row.addedOn).sort().at(-1)),
			},
		)

	const systemNotes = systems
		.filter((system) => isLate(data, system) || system.ownsNoData)
		.map((system) => {
			const paragraphs = []
			if (isLate(data, system)) {
				paragraphs.push(t(
					'We read {system} on {date}, not on the date above. Its column joined this table on {added}.',
					{
						system: system.name,
						date: date(system.readOn),
						added: date(system.columnAddedOn ?? system.readOn),
					},
				))
			}
			if (system.ownsNoData) {
				paragraphs.push(t(
					'{system} owns no data. The case, its documents and its retention live in {register}. A row we score in our own shape often falls outside {system} by design. Its column reads low for that reason, which flatters us. Read the gap as a difference in architecture, not as a weaker product.',
					{ system: system.name, register: system.ownsNoData },
				))
			}
			return {
				key: system.key,
				heading: t('About the {system} column', { system: system.name }),
				paragraphs,
			}
		})

	// The caveats, in the order the tab showed them, and all of them before
	// the first score. Empty sentences drop out rather than leave a blank
	// paragraph behind.
	const caveats = [
		t('We only compared open source software we could install and run ourselves. Closed and hosted products are not in this table. Their absence is not a verdict on them.'),
		readingDateText,
		t('A rating is our reading of software we did not write. It is not proof that a product does or does not have a capability.'),
		t('We strongly advise you to run your own evaluation. This table does not replace testing against your own requirements.'),
		shortlistText,
		reratedText,
		addedRowsText,
		pendingText,
		t('Every system here is a municipal case system or a workflow engine, and the list is drawn from what they do, in the shape we do it. A capability none of them has is missing from the list, not from the market. A product that splits the work differently scores low without being worse, and that bias runs in our favour. We add rows as we read more systems, so a lower score in a later release can mean the list grew rather than the product shrank.'),
	].filter(Boolean)

	return {
		leadText,
		caveats,
		systemNotes,
	}
}

/**
 * Short label for a rating. The word carries the meaning, never colour alone.
 *
 * @param {string} locale Docusaurus locale.
 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
 * @return {string} Translated label.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function ratingLabel(locale, rating) {
	const labels = { yes: 'Yes', partial: 'Partly', no: 'Not found' }
	return translate(locale, labels[rating] ?? 'Unknown')
}

/**
 * What a rating means, for the legend.
 *
 * @param {string} locale Docusaurus locale.
 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
 * @return {string} Translated explanation.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function ratingMeaning(locale, rating) {
	const meanings = {
		yes: 'we found the whole capability',
		partial: 'we found part of it, and part is missing',
		unknown: 'we have not read that system on this row',
	}
	return translate(locale, meanings[rating] ?? 'we did not find it')
}

/**
 * One-line score for an area, on the disclosure summary.
 *
 * @param {string} locale Docusaurus locale.
 * @param {object} area Grouped area from `groupByArea`.
 * @return {string} Translated summary.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function areaSummary(locale, area) {
	const self = area.tallies.dossiq
	if (area.capabilities.length === 0) {
		return translate(locale, '{count} proposed, none rated yet.', {
			count: area.pending.length,
		})
	}
	const vars = {
		total: area.capabilities.length,
		yes: self.yes,
		partial: self.partial,
		no: self.no,
		pending: area.pending.length,
	}
	return area.pending.length > 0
		? translate(
			locale,
			'{total} capabilities. Dossiq has {yes}, partly has {partial}, is missing {no}. {pending} more proposed.',
			vars,
		)
		: translate(
			locale,
			'{total} capabilities. Dossiq has {yes}, partly has {partial}, is missing {no}.',
			vars,
		)
}
