/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "How dossiq compares", served at /compare and /nl/compare.
 *
 * A .js page and not .mdx: docs.path is './', so the docs plugin's MDX loader
 * also matches files under src/pages/ and runs over the pages plugin's output
 * a second time, which fails the build. The prose is short enough to keep in
 * two languages here.
 */

import React from 'react'
import Layout from '@theme/Layout'
import Link from '@docusaurus/Link'
import useDocusaurusContext from '@docusaurus/useDocusaurusContext'
import CapabilityComparison from '@site/src/components/CapabilityComparison'

const PROSE = {
	en: {
		title: 'How dossiq compares',
		description:
			'We rated dossiq and other open source case management systems on the same list of capabilities. Read the limits of that reading before the scores.',
		intro: [
			'We installed other open source case systems, read their code and rated each one on the same list of capabilities.',
			'The limits of that reading come first, then the totals, then every capability by area.',
		],
		next: 'What to do next',
		nextText:
			'Write down the capabilities your organisation needs, and test every system on that shortlist yourself.',
		tryIt: 'To try dossiq, follow the',
		guide: 'installation guide',
	},
	nl: {
		title: 'Hoe dossiq zich verhoudt',
		description:
			'We beoordeelden dossiq en andere open source zaaksystemen op dezelfde lijst functies. Lees eerst de grenzen van die beoordeling, dan de scores.',
		intro: [
			'We installeerden andere open source zaaksystemen, lazen hun code en beoordeelden ze allemaal op dezelfde lijst functies.',
			'Eerst lees je de grenzen van die beoordeling, dan de totalen, dan elke functie per gebied.',
		],
		next: 'Wat je nu doet',
		nextText:
			'Schrijf op welke functies jouw organisatie nodig heeft, en test elk systeem zelf op die shortlist.',
		tryIt: 'Wil je dossiq proberen? Volg de',
		guide: 'installatiehandleiding',
	},
}

/**
 * The comparison page.
 *
 * @return {React.ReactElement} The page.
 */
export default function Compare() {
	const { i18n } = useDocusaurusContext()
	const prose = PROSE[i18n.currentLocale] ?? PROSE.en
	return (
		<Layout title={prose.title} description={prose.description}>
			<main className="container margin-vert--lg">
				<h1>{prose.title}</h1>
				{prose.intro.map((line) => (
					<p key={line}>{line}</p>
				))}
				<CapabilityComparison />
				<h2>{prose.next}</h2>
				<p>{prose.nextText}</p>
				<p>
					{prose.tryIt} <Link to="/docs/installation">{prose.guide}</Link>.
				</p>
			</main>
		</Layout>
	)
}
