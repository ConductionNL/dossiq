<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - One screen to close out the day.
  -
  - WHAT "TOUCHED" MEANS, AND WHY IT IS THE REGISTER'S ANSWER. The list is the
  - reader's own queue candidates narrowed to the ones OpenRegister says THIS
  - reader has opened today. dossiq keeps no second record of who saw what, per
  - `unread-state-on-the-case`; and "changed today" would have been the wrong
  - question anyway, since it lists a colleague's afternoon as yours.
  -
  - 🔴 TIME IS HUMANIQ'S, OR NOBODY'S. The screen places humaniq's hours leaf
  - per item and offers no time field of its own. On an instance without
  - humaniq there is no time field at all, rather than a dossiq one that would
  - become a second hours store the day humaniq arrives.
  -
  - @visual exclude Every row on this screen is seeded work belonging to the signed-in person, so a baseline would capture one run's fixture data and diff against the next run's. The layout it would guard is a heading, a list and one button per group; what is worth asserting is WHICH rows appear and what closes them, and tests/e2e/one-personal-queue.spec.ts asserts exactly that.
-->
<template>
	<div class="end-of-day">
		<header class="end-of-day__head">
			<h1 class="end-of-day__title">
				{{ t('dossiq', 'Close out your day') }}
			</h1>
			<CnBreadcrumbs class="end-of-day__crumbs" :crumbs="crumbs" />
		</header>

		<NcNoteCard
			v-for="source in unavailable"
			:key="source.source"
			type="warning">
			{{
				t(
					'dossiq',
					'{source} could not be read, so this list is incomplete.',
					{ source: source.label },
				)
			}}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-else class="end-of-day__columns">
			<div class="end-of-day__main">
				<section class="end-of-day__card" data-testid="end-of-day-open">
					<div class="end-of-day__card-head">
						<h2 class="end-of-day__card-title">
							{{ t('dossiq', 'Still open from today') }}
							<span class="end-of-day__count end-of-day__count--open">{{ openItems.length }}</span>
						</h2>
						<span class="end-of-day__card-note">
							{{ t('dossiq', 'what you opened today and has no note yet') }}
						</span>
					</div>

					<p
						v-if="!openItems.length"
						class="end-of-day__empty"
						data-testid="end-of-day-empty">
						{{ items.length ? t('dossiq', 'Everything you opened today has a note.') : t('dossiq', 'You have not opened anything today.') }}
					</p>

					<div
						v-for="item in openItems"
						:key="item.id"
						class="end-of-day__item"
						:data-testid="`end-of-day-item-${item.id}`">
						<div class="end-of-day__item-head">
							<span class="end-of-day__kind" :class="`end-of-day__kind--${item.subjectType}`">{{ kindLabel(item) }}</span>
							<router-link
								v-if="item.route"
								class="end-of-day__item-title"
								:to="item.route">
								{{ item.title }}
							</router-link>
							<span v-else class="end-of-day__item-title">{{ item.title }}</span>
							<span class="end-of-day__spacer" />
							<span
								v-if="dueText(item)"
								class="end-of-day__due"
								:class="`end-of-day__due--${dueTone(item)}`">{{ dueText(item) }}</span>
						</div>

						<div class="end-of-day__fields">
							<NcTextField
								v-model="updates[item.id]"
								class="end-of-day__what"
								:label="t('dossiq', 'What happened')"
								:labelOutside="true"
								:placeholder="t('dossiq', 'For example: called the resident, waiting for documents')"
								:data-testid="`end-of-day-update-${item.id}`" />

							<!-- The time box, and only when humaniq can hold it. -->
							<div
								v-if="hoursLeafAvailable"
								class="end-of-day__hours"
								:data-testid="`end-of-day-hours-${item.id}`">
								<component
									:is="hoursLeaf"
									:objectId="item.subjectId"
									register="dossiq"
									:schema="item.subjectType" />
							</div>

							<NcButton
								variant="secondary"
								:disabled="!hasUpdate(item)"
								:data-testid="`end-of-day-save-${item.id}`"
								@click="record(item)">
								{{ t('dossiq', 'Record it') }}
							</NcButton>
						</div>
						<p
							v-if="!hoursLeafAvailable && humaniqPresent"
							class="end-of-day__no-leaf"
							:data-testid="`end-of-day-hours-unavailable-${item.id}`">
							{{
								t(
									'dossiq',
									'The hours leaf is not available, so time cannot be recorded here.',
								)
							}}
						</p>
					</div>
				</section>

				<section class="end-of-day__card" data-testid="end-of-day-recorded">
					<div class="end-of-day__card-head">
						<h2 class="end-of-day__card-title">
							{{ t('dossiq', 'Recorded today') }}
							<span class="end-of-day__count end-of-day__count--done">{{ recorded.length }}</span>
						</h2>
						<span class="end-of-day__card-note">
							{{ t('dossiq', 'it is on the case or task, for whoever opens it tomorrow') }}
						</span>
					</div>
					<p v-if="!recorded.length" class="end-of-day__empty">
						{{ t('dossiq', 'Nothing recorded yet today.') }}
					</p>
					<div
						v-for="entry in recorded"
						:key="entry.item.id"
						class="end-of-day__done">
						<span class="end-of-day__check" aria-hidden="true">
							<CheckIcon :size="16" />
						</span>
						<div class="end-of-day__done-text">
							<div class="end-of-day__item-head">
								<span class="end-of-day__kind" :class="`end-of-day__kind--${entry.item.subjectType}`">{{ kindLabel(entry.item) }}</span>
								<router-link
									v-if="entry.item.route"
									class="end-of-day__done-title"
									:to="entry.item.route">
									{{ entry.item.title }}
								</router-link>
								<span v-else class="end-of-day__done-title">{{ entry.item.title }}</span>
								<span class="end-of-day__meta">{{ entry.time }}</span>
							</div>
							<p class="end-of-day__done-note">
								{{ entry.text }}
							</p>
						</div>
					</div>
				</section>

				<section class="end-of-day__card end-of-day__card--close">
					<div class="end-of-day__close-body">
						<h2 class="end-of-day__close-title">
							{{ t('dossiq', 'Ready for today?') }}
						</h2>
						<p class="end-of-day__close-text">
							{{ t('dossiq', 'The button records every note you filled in on its own case or task. An item without a note stays open and is back in My work tomorrow.') }}
						</p>
					</div>
					<div class="end-of-day__close-actions">
						<NcButton variant="secondary" :to="{ name: 'MyWorkHome' }">
							{{ t('dossiq', 'Cancel') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="!filledItems.length || busy"
							data-testid="end-of-day-close"
							@click="closeDay">
							<template #icon>
								<ClipboardCheckOutline :size="20" />
							</template>
							{{ t('dossiq', 'Close out your day') }}
						</NcButton>
					</div>
				</section>
			</div>

			<aside class="end-of-day__aside">
				<section class="end-of-day__card end-of-day__card--side">
					<h2 class="end-of-day__side-title">
						{{ t('dossiq', 'Your day') }}
					</h2>
					<dl class="end-of-day__figures">
						<div>
							<dt>{{ t('dossiq', 'Opened') }}</dt>
							<dd class="end-of-day__figure">
								{{ items.length }}
							</dd>
							<dd class="end-of-day__figure-note">
								{{ t('dossiq', '{cases} cases, {tasks} tasks', { cases: countOf('case'), tasks: countOf('task') }) }}
							</dd>
						</div>
						<div>
							<dt>{{ t('dossiq', 'Notes recorded') }}</dt>
							<dd class="end-of-day__figure">
								{{ recorded.length }}
							</dd>
							<dd class="end-of-day__figure-note">
								{{ t('dossiq', 'of the {total}', { total: items.length }) }}
							</dd>
						</div>
						<div>
							<dt>{{ t('dossiq', 'Ready to record') }}</dt>
							<dd class="end-of-day__figure">
								{{ filledItems.length }}
							</dd>
							<dd class="end-of-day__figure-note">
								{{ t('dossiq', 'filled in, not recorded yet') }}
							</dd>
						</div>
					</dl>
				</section>

				<section class="end-of-day__card end-of-day__card--side">
					<h2 class="end-of-day__side-title">
						{{ t('dossiq', 'How this works') }}
					</h2>
					<p class="end-of-day__side-text">
						{{ t('dossiq', 'This lists what you opened yourself today, not what a colleague changed. A note goes on the case or task itself. Hours go to humaniq.') }}
					</p>
				</section>
			</aside>
		</div>
	</div>
</template>

<script>
import { CnBreadcrumbs } from '@conduction/nextcloud-vue'
import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { leafTab } from '../../integrations/leafTabs.js'
import { fetchEndOfDay } from '../../services/personalQueueApi.js'
import { fetchReadState } from '../../services/readStateApi.js'
import { recordUpdateOn } from '../../utils/endOfDayHelpers.js'
import {
	HOURS_LEAF_ID,
	humaniqIsPresent,
	todayOf,
	touchedToday,
} from '../../utils/personalQueueHelpers.js'

export default {
	name: 'EndOfDayView',

	components: {
		CheckIcon,
		ClipboardCheckOutline,
		CnBreadcrumbs,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			loading: false,
			items: [],
			unavailable: [],
			updates: {},
			recorded: [],
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The trail back to My work, ending on today.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		crumbs() {
			let today
			try {
				today = new Intl.DateTimeFormat(getCanonicalLocale() || undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())
			} catch {
				today = new Date().toDateString()
			}
			return [
				{ label: t('dossiq', 'My work'), to: { name: 'MyWorkHome' } },
				{ label: t('dossiq', 'Close out your day, {date}', { date: today }) },
			]
		},

		/**
		 * @return {Array<object>} The items opened today that carry no recorded note yet.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		openItems() {
			const done = new Set(this.recorded.map((entry) => entry.item.id))
			return this.items.filter((item) => !done.has(item.id))
		},

		/**
		 * @return {Array<object>} The open items with a note typed but not recorded.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		filledItems() {
			return this.openItems.filter((item) => this.hasUpdate(item))
		},

		/**
		 * @return {boolean} TRUE when humaniq is on this instance.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		humaniqPresent() {
			return humaniqIsPresent()
		},

		/**
		 * @return {object|undefined} humaniq's hours leaf, when it registered one.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		hoursLeaf() {
			return leafTab(HOURS_LEAF_ID)
		},

		/**
		 * @return {boolean} TRUE when time can actually be recorded.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		hoursLeafAvailable() {
			return this.humaniqPresent && this.hoursLeaf !== undefined
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Read the candidates and keep the ones seen today.
		 *
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const { items, unavailable } = await fetchEndOfDay()
				this.unavailable = unavailable

				// One request per item, in parallel. Sequentially this was a
				// round trip per candidate, so a handler with twenty open
				// cases waited for twenty of them in a row before the screen
				// showed anything.
				const states = {}
				const read = await Promise.all(
					items.map((item) =>
						fetchReadState(item.subjectId, 'dossiq', item.subjectType)
							.then((state) => [item.id, state])
							.catch(() => [item.id, null]),
					),
				)
				for (const [id, state] of read) {
					states[id] = state
				}

				this.items = touchedToday(items, states, todayOf())
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the reader wrote something about this item.
		 *
		 * @param {object} item The item.
		 * @return {boolean} TRUE when there is something to record.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		hasUpdate(item) {
			return String(this.updates[item.id] ?? '').trim() !== ''
		},

		/**
		 * Record the update on the thing it is about.
		 *
		 * @param {object} item The item.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async record(item) {
			const text = String(this.updates[item.id] ?? '').trim()
			const written = await recordUpdateOn(item, text)
			this.updates = { ...this.updates, [item.id]: '' }
			if (written) {
				const time = new Date().toLocaleTimeString(getCanonicalLocale() || undefined, { hour: '2-digit', minute: '2-digit' })
				this.recorded = [{ item, text, time }, ...this.recorded]
			}
		},

		/**
		 * Record every note that was filled in, one item after the other.
		 *
		 * @return {Promise<void>} When every write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async closeDay() {
			this.busy = true
			try {
				for (const item of this.filledItems) {
					await this.record(item)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} item The item.
		 * @return {string} What kind of thing the item is, as the chip shows it.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		kindLabel(item) {
			return item.subjectType === 'task' ? t('dossiq', 'Task') : t('dossiq', 'Case')
		},

		/**
		 * @param {string} kind A subject type.
		 * @return {number} How many of today's items are of that kind.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		countOf(kind) {
			return this.items.filter((item) => item.subjectType === kind).length
		},

		/**
		 * @param {object} item The item.
		 * @return {number|null} Whole days from today to the item's due date, negative when past.
		 */
		daysLeft(item) {
			if (!item.dueAt) {
				return null
			}
			const due = new Date(String(item.dueAt).slice(0, 10) + 'T00:00:00')
			if (Number.isNaN(due.getTime())) {
				return null
			}
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			return Math.round((due - today) / 86400000)
		},

		/**
		 * @param {object} item The item.
		 * @return {string} The due line on the right of the item, or empty.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		dueText(item) {
			const days = this.daysLeft(item)
			if (days === null) {
				return ''
			}
			if (days < 0) {
				return n('dossiq', '%n day late', '%n days late', -days)
			}
			if (days === 0) {
				return t('dossiq', 'Term ends today')
			}
			return new Date(String(item.dueAt).slice(0, 10) + 'T00:00:00').toLocaleDateString(getCanonicalLocale() || undefined, { weekday: 'long', day: 'numeric', month: 'short' })
		},

		/**
		 * @param {object} item The item.
		 * @return {string} `error` when due today or late, `warning` otherwise.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		dueTone(item) {
			const days = this.daysLeft(item)
			return days !== null && days <= 0 ? 'error' : 'warning'
		},
	},
}
</script>

<style scoped>
.end-of-day {
	display: flex;
	flex-direction: column;
	gap: 20px;
	max-width: 1240px;
	box-sizing: border-box;
	padding: 24px 28px;
}

.end-of-day__head {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 4px 0 8px;
}

.end-of-day__title {
	margin: 0;
	font-size: 28px;
	font-weight: 700;
	line-height: 1.2;
}

.end-of-day__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 20px;
	align-items: flex-start;
}

.end-of-day__main {
	flex: 999 1 520px;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.end-of-day__aside {
	flex: 1 1 280px;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.end-of-day__card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 20px 22px 10px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
}

.end-of-day__card-head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px 12px;
	margin-bottom: 6px;
}

.end-of-day__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0;
	font-size: 18px;
	font-weight: 700;
}

.end-of-day__card-note,
.end-of-day__meta {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.end-of-day__meta {
	font-size: 13px;
}

.end-of-day__count {
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 13px;
	font-weight: 600;
}

.end-of-day__count--open {
	background: var(--color-warning-light, var(--color-background-dark));
	color: var(--color-warning-text);
}

.end-of-day__count--done {
	background: var(--color-success-light, var(--color-background-dark));
	color: var(--color-success-text);
}

.end-of-day__item {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px 0;
	border-top: 1px solid var(--color-border);
}

.end-of-day__item-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}

.end-of-day__kind {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.end-of-day__kind--task {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.end-of-day__item-title {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	text-decoration: underline;
}

.end-of-day__spacer {
	flex: 1;
}

.end-of-day__due {
	font-size: 14px;
	font-weight: 600;
	white-space: nowrap;
}

.end-of-day__due--error {
	font-weight: 700;
	color: var(--color-error-text);
}

.end-of-day__due--warning {
	color: var(--color-warning-text);
}

.end-of-day__fields {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
}

.end-of-day__what {
	flex: 1 1 360px;
}

.end-of-day__hours {
	flex: 0 0 104px;
}

.end-of-day__done {
	display: flex;
	gap: 14px;
	padding: 14px 0;
	border-top: 1px solid var(--color-border);
}

.end-of-day__check {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	width: 24px;
	height: 24px;
	border-radius: 12px;
	background: var(--color-success-light, var(--color-background-dark));
	color: var(--color-success-text);
}

.end-of-day__done-text {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.end-of-day__done-title {
	font-size: 15px;
	font-weight: 600;
	color: var(--color-main-text);
	text-decoration: underline;
}

.end-of-day__done-note {
	margin: 0;
	font-size: 15px;
}

.end-of-day__card--close {
	padding: 0;
	gap: 0;
}

.end-of-day__close-body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 22px 24px 20px;
}

.end-of-day__close-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}

.end-of-day__close-text {
	margin: 0;
	font-size: 15px;
	line-height: 1.55;
}

.end-of-day__close-actions {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 14px 24px;
	border-top: 1px solid var(--color-border);
}

.end-of-day__card--side {
	padding: 20px 22px;
	gap: 12px;
}

.end-of-day__side-title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.end-of-day__figures {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 14px 16px;
	margin: 0;
}

.end-of-day__figures dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.end-of-day__figures dd {
	margin: 0;
}

.end-of-day__figure {
	font-size: 22px;
	font-weight: 700;
}

.end-of-day__figure-note {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.end-of-day__side-text {
	margin: 0;
	font-size: 15px;
	line-height: 1.55;
}

.end-of-day__empty,
.end-of-day__no-leaf {
	margin: 0;
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
}
</style>
