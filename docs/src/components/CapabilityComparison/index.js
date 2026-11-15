/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The capability comparison, rendered on the docs site.
 *
 * It reads `openspec/parity/capabilities.json` straight from the repo, the
 * file the sync script re-issues from the parity corpus. Nothing is copied
 * at build time, so a re-issue changes this page on the next docs build and
 * there is no second copy to forget. The grouping and the tallies come from
 * `src/utils/capabilityComparison.js`, the helpers the in-app tab used.
 *
 * Every rated row is on the page, grouped by area. A search box and a rating
 * filter narrow the rows; the totals stay over the whole list, because a
 * total over a filtered list would read as a different score.
 */

import React, { useMemo, useState } from 'react'
import Admonition from '@theme/Admonition'
import useDocusaurusContext from '@docusaurus/useDocusaurusContext'
import data from '../../../../openspec/parity/capabilities.json'
import {
	groupByArea,
	overallTallies,
	RATING_COLUMNS,
} from '../../../../src/utils/capabilityComparison.js'
import {
	areaSummary,
	comparisonCopy,
	ratingLabel,
	ratingMeaning,
	translate,
} from './comparisonCopy.js'
import styles from './styles.module.css'

/**
 * A rating chip. The word carries the meaning, the colour only repeats it.
 *
 * @param {object} props Component props.
 * @param {string} props.locale Docusaurus locale.
 * @param {string} props.rating The rating.
 * @return {React.ReactElement} The chip.
 */
function Chip({ locale, rating }) {
	const known = RATING_COLUMNS.includes(rating) ? rating : 'unknown'
	return (
		<span className={`${styles.chip} ${styles[`chip_${known}`]}`}>
			{ratingLabel(locale, rating)}
		</span>
	)
}

/**
 * Whether a row passes the search text and the rating filter.
 *
 * @param {object} row A row with a resolved `label`.
 * @param {string} query Lower-cased search text.
 * @param {string} rating Rating the self column must have, or empty.
 * @param {string} selfKey Key of our own column.
 * @return {boolean} True when the row stays visible.
 */
function matches(row, query, rating, selfKey) {
	if (rating && row[selfKey] !== rating) {
		return false
	}
	if (!query) {
		return true
	}
	return (
		row.label.toLowerCase().includes(query)
		|| String(row.name ?? '').toLowerCase().includes(query)
		|| String(row.id).startsWith(query)
	)
}

/**
 * The comparison: caveats first, then the totals, then every row by area.
 *
 * @return {React.ReactElement} The comparison.
 */
export default function CapabilityComparison() {
	const { i18n } = useDocusaurusContext()
	const locale = i18n.currentLocale
	const t = (key, vars) => translate(locale, key, vars)

	const [query, setQuery] = useState('')
	const [rating, setRating] = useState('')

	const copy = useMemo(() => comparisonCopy(data, locale), [locale])
	const areas = useMemo(() => groupByArea(data, locale), [locale])
	const totals = useMemo(() => overallTallies(data), [])
	const systems = data.systems
	const self = systems.find((system) => system.isSelf)
	const total = data.capabilities.length

	const needle = query.trim().toLowerCase()
	const filtering = needle !== '' || rating !== ''
	const shown = areas
		.map((area) => ({
			...area,
			rows: area.capabilities.filter((row) => matches(row, needle, rating, self.key)),
			proposals: area.pending.filter((row) => matches(row, needle, rating, self.key)),
		}))
		.filter((area) => !filtering || area.rows.length + area.proposals.length > 0)
	const matchCount = shown.reduce((sum, area) => sum + area.rows.length, 0)

	const selfClass = (system) => (system.isSelf ? styles.self : undefined)

	return (
		<div className={styles.comparison}>
			<p className={styles.lead}>{copy.leadText}</p>

			<Admonition type="info" title={t('Before you use this table')}>
				{copy.caveats.map((caveat, index) => (
					<p key={index}>{caveat}</p>
				))}
			</Admonition>

			{copy.systemNotes.map((note) => (
				<Admonition key={note.key} type="info" title={note.heading}>
					{note.paragraphs.map((paragraph, index) => (
						<p key={index}>{paragraph}</p>
					))}
				</Admonition>
			))}

			<h2>{t('Totals over all {count} capabilities', { count: total })}</h2>
			<div className={styles.scroller}>
				<table className={styles.table}>
					<caption className={styles.caption}>
						{t('How many of the {count} capabilities each system has.', { count: total })}
					</caption>
					<thead>
						<tr>
							<th scope="col">{t('System')}</th>
							{RATING_COLUMNS.map((column) => (
								<th key={column} scope="col">{ratingLabel(locale, column)}</th>
							))}
						</tr>
					</thead>
					<tbody>
						{systems.map((system) => (
							<tr key={system.key} className={selfClass(system)}>
								<th scope="row">{system.name}</th>
								{RATING_COLUMNS.map((column) => (
									<td key={column}>{totals[system.key][column]}</td>
								))}
							</tr>
						))}
					</tbody>
				</table>
			</div>

			<h2>{t('Per area')}</h2>
			<p className={styles.legend}>
				{RATING_COLUMNS.map((column) => (
					<span key={column} className={styles.legendItem}>
						<Chip locale={locale} rating={column} />
						{ratingMeaning(locale, column)}
					</span>
				))}
			</p>

			<div className={styles.filters}>
				<label className={styles.filter}>
					<span>{t('Search capabilities')}</span>
					<input
						type="search"
						value={query}
						onChange={(event) => setQuery(event.target.value)} />
				</label>
				<label className={styles.filter}>
					<span>{t('Rating for {system}', { system: self.name })}</span>
					<select value={rating} onChange={(event) => setRating(event.target.value)}>
						<option value="">{t('Any rating')}</option>
						{RATING_COLUMNS.map((column) => (
							<option key={column} value={column}>{ratingLabel(locale, column)}</option>
						))}
					</select>
				</label>
			</div>
			<p className={styles.status} role="status">
				{filtering
					? (matchCount + shown.reduce((sum, area) => sum + area.proposals.length, 0) === 0
						? t('No capability matches. Clear the search or pick another rating.')
						: t('{count} of the {total} rated capabilities match.', { count: matchCount, total }))
					: ''}
			</p>

			{shown.map((area) => (
				<details key={area.key} className={styles.area} open={filtering}>
					<summary className={styles.areaSummary}>
						<span className={styles.areaName}>{area.label}</span>
						<span className={styles.areaCount}>{areaSummary(locale, area)}</span>
					</summary>
					{area.rows.length > 0 && (
						<div className={styles.scroller}>
							<table className={styles.table}>
								<caption className={styles.caption}>
									{t('Capabilities in {area}, rated for each of the {count} systems.', {
										area: area.label,
										count: systems.length,
									})}
								</caption>
								<thead>
									<tr>
										<th scope="col" className={styles.num}>{t('No.')}</th>
										<th scope="col">{t('Capability')}</th>
										{systems.map((system) => (
											<th key={system.key} scope="col" className={selfClass(system)}>
												{system.name}
											</th>
										))}
									</tr>
								</thead>
								<tbody>
									{area.rows.map((row) => (
										<tr key={row.id}>
											<td className={styles.num}>{row.id}</td>
											<th scope="row" className={styles.cap}>{row.label}</th>
											{systems.map((system) => (
												<td key={system.key} className={selfClass(system)}>
													<Chip locale={locale} rating={row[system.key]} />
												</td>
											))}
										</tr>
									))}
								</tbody>
							</table>
						</div>
					)}
					{area.proposals.length > 0 && (
						<>
							<h3 className={styles.pendingHeading}>{t('Proposed, not yet rated')}</h3>
							<div className={styles.scroller}>
								<table className={styles.table}>
									<caption className={styles.caption}>
										{t('{count} capabilities proposed for {area}. We rated ourselves. The other {others} systems have not been read against these, so they are in no total here.', {
											count: area.pending.length,
											area: area.label,
											others: systems.length - 1,
										})}
									</caption>
									<thead>
										<tr>
											<th scope="col" className={styles.num}>{t('No.')}</th>
											<th scope="col">{t('Capability')}</th>
											<th scope="col" className={styles.self}>{self.name}</th>
										</tr>
									</thead>
									<tbody>
										{area.proposals.map((row) => (
											<tr key={row.id} className={styles.pending}>
												<td className={styles.num}>{row.id}</td>
												<th scope="row" className={styles.cap}>{row.label}</th>
												<td className={styles.self}>
													<Chip locale={locale} rating={row[self.key]} />
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>
						</>
					)}
				</details>
			))}
		</div>
	)
}
