<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	My work, "First today": ONE case, the one of yours furthest past its
	deadline (or ending today), with the acts you take on it.

	The library banner counts ("{value} of your cases ...") and cannot name a
	case, and the board (DqMijnWerk) names one. This draws the library's own
	attention card, with its classes, around the case it found. Silent when
	nothing of yours is due.

	@spec openspec/specs/dashboard/spec.md
-->
<template>
	<component
		:is="banner"
		v-if="found && banner"
		layout="attention"
		variant="error"
		:kicker="t('dossiq', 'First today')"
		:title="found.title"
		:reason="reason"
		:actions="actions"
		data-testid="first-today" />
</template>

<script>
import { getWidgetTypeEntry } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

/** Milliseconds in a day. */
const DAY = 86400000

export default {
	name: 'FirstTodayWidget',

	// Declared on purpose: the mount script passes `title`, and the host draws
	// the heading (hydra#316). See OverdueCasesWidget.
	props: {
		// eslint-disable-next-line vue/no-unused-properties
		title: {
			type: String,
			default: '',
		},

		/**
		 * The declaration of what is past term: `visibleWhen.source.filter` is
		 * what the widget counts on, `actions[0]` the list it links to.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			found: null,
			caseTypeName: '',
			headingId: 'dossiq-first-today-title',
		}
	},

	computed: {
		/**
		 * The library's banner, taken from the widget registry by its type
		 * key. CnBannerWidget is not a public export of nextcloud-vue (its
		 * index says so): a named import resolves to undefined, Vue then
		 * renders an unknown <cnbannerwidget> element, and the grid keeps an
		 * empty card for it. That was the blank block under the My work / My
		 * team switch, seen only by a reader with a case past its term.
		 *
		 * @return {object|null} The banner component, or null.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		banner() {
			return getWidgetTypeEntry('banner')?.renderer ?? null
		},

		/**
		 * The sentence under the title: number, type, and how late.
		 *
		 * @return {string} The reason line.
		 */
		reason() {
			if (!this.found) {
				return ''
			}
			const parts = [this.found.identifier, this.caseTypeName].filter(Boolean)
			const days = this.daysPast(this.found)
			const when =
				days > 0
					? t('dossiq', 'the term ran out {days} days ago.', { days })
					: t('dossiq', 'the term ends today.')
			return `${parts.join(' · ')} · ${when}`
		},

		/**
		 * The acts on this case, the case page the one that stands out. The
		 * board draws four; CnBannerWidget takes two (library gap: first-today
		 * actions), so the board link, which is in the menu, is the one left out.
		 *
		 * @return {Array<object>} The actions.
		 */
		actions() {
			return [
				{
					id: 'all',
					label: t(
						'dossiq',
						this.content?.actions?.[0]?.label ?? 'Open these cases',
					),

					route: this.content?.actions?.[0]?.route ?? { name: 'Cases' },
				},
				{
					id: 'open',
					label: t('dossiq', 'Open case'),
					primary: true,
					route: { name: 'CaseDetail', params: { id: this.found.id } },
				},
			]
		},
	},

	async mounted() {
		await initializeStores()
		await this.load()
	},

	methods: {
		t,

		/**
		 * Find the one case: mine, open, furthest past its deadline.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			const userId = getCurrentUser()?.uid
			if (!userId) {
				return
			}
			const store = useObjectStore()
			const tomorrow = new Date(Date.now() + DAY).toISOString().slice(0, 10)
			try {
				const rows = await store.fetchCollection('case', {
					...this.pastTermFilter(userId, tomorrow),
					_order: JSON.stringify({ deadline: 'asc' }),
					_limit: 1,
				})
				const row = (rows || [])[0] || null
				this.found = row
				if (row?.caseType) {
					const types = await store.fetchCollection('caseType', {
						_limit: 100,
					})
					const type = (types || []).find(
						(item) => item.id === row.caseType,
					)
					this.caseTypeName = type?.title || ''
				}
			} catch {
				this.found = null
			}
		},

		/**
		 * The declared filter of what is past term, tokens resolved.
		 *
		 * @param {string} userId The reader.
		 * @param {string} tomorrow The day the term must lie before, Y-m-d.
		 * @return {object} The query parameters.
		 */
		pastTermFilter(userId, tomorrow) {
			const declared = this.content?.visibleWhen?.source?.filter ?? {}
			const out = {}
			for (const [key, value] of Object.entries(declared)) {
				if (value === '@me') {
					out[key] = userId
				} else if (value === '@today+1d') {
					out[key] = tomorrow
				} else {
					out[key] = value
				}
			}
			return out
		},

		/**
		 * Whole days between the case's deadline and today.
		 *
		 * @param {object} row The case.
		 * @return {number} Days past, 0 on the last day.
		 */
		daysPast(row) {
			const deadline = Date.parse(row.deadline)
			if (Number.isNaN(deadline)) {
				return 0
			}
			const today = new Date().setHours(0, 0, 0, 0)
			return Math.max(0, Math.round((today - deadline) / DAY))
		},
	},
}
</script>
