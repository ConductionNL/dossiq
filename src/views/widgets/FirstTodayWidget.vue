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
	<section
		v-if="found"
		class="cn-banner-widget cn-banner-widget--attention cn-banner-widget--error"
		:aria-labelledby="headingId"
		data-testid="first-today">
		<div class="cn-banner-widget__body">
			<p class="cn-banner-widget__kicker">
				{{ t('dossiq', 'First today') }}
			</p>
			<h3 :id="headingId" class="cn-banner-widget__title">
				{{ found.title }}
			</h3>
			<p class="cn-banner-widget__reason">
				{{ reason }}
			</p>
		</div>
		<div class="cn-banner-widget__actions">
			<router-link
				v-for="action in actions"
				:key="action.key"
				class="cn-banner-widget__action"
				:class="
					action.primary
						? 'cn-banner-widget__action--primary'
						: 'cn-banner-widget__action--secondary'
				"
				:to="action.to"
				data-testid="first-today-action">
				{{ action.label }}
			</router-link>
		</div>
	</section>
</template>

<script>
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
		 * The acts on this case, the case page last and the one that stands out.
		 *
		 * @return {Array<object>} The actions.
		 */
		actions() {
			return [
				{
					key: 'all',
					label: t('dossiq', 'All cases past their deadline'),
					to: {
						name: 'Cases',
						query: {
							assignee: '@me',
							isFinalStatus: 'false',
							statusHiddenInLists: 'false',
							isDraft: 'false',
							'deadline[lt]': '@today+1d',
						},
					},
				},
				{
					key: 'board',
					label: t('dossiq', 'Open the board'),
					to: { name: 'WorkflowBoard' },
				},
				{
					key: 'open',
					label: t('dossiq', 'Open case'),
					primary: true,
					to: { name: 'CaseDetail', params: { id: this.found.id } },
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
					assignee: userId,
					isFinalStatus: false,
					statusHiddenInLists: false,
					isDraft: false,
					'deadline[lt]': tomorrow,
					_order: JSON.stringify({ deadline: 'asc' }),
					_limit: 1,
				})
				const row = (rows || [])[0] || null
				this.found = row
				if (row?.caseType) {
					const types = await store.fetchCollection('caseType', { _limit: 100 })
					const type = (types || []).find((item) => item.id === row.caseType)
					this.caseTypeName = type?.title || ''
				}
			} catch {
				this.found = null
			}
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
