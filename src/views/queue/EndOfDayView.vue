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
-->
<template>
	<div class="end-of-day">
		<h2 class="end-of-day__title">
			{{ t('dossiq', 'Close out your day') }}
		</h2>

		<NcNoteCard
			v-for="source in unavailable"
			:key="source.source"
			type="warning">
			{{ t('dossiq', '{source} could not be read, so this list is incomplete.', { source: source.label }) }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<p v-if="!items.length" class="end-of-day__empty" data-testid="end-of-day-empty">
				{{ t('dossiq', 'You have not opened anything today.') }}
			</p>

			<section
				v-for="item in items"
				:key="item.id"
				class="end-of-day__item"
				:data-testid="`end-of-day-item-${item.id}`">
				<h3 class="end-of-day__item-title">
					{{ item.title }}
				</h3>

				<NcTextField
					v-model="updates[item.id]"
					:label="t('dossiq', 'What happened')"
					:data-testid="`end-of-day-update-${item.id}`" />

				<NcButton
					variant="secondary"
					:disabled="!hasUpdate(item)"
					:data-testid="`end-of-day-save-${item.id}`"
					@click="record(item)">
					{{ t('dossiq', 'Record it') }}
				</NcButton>

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
				<p
					v-else-if="humaniqPresent"
					class="end-of-day__no-leaf"
					:data-testid="`end-of-day-hours-unavailable-${item.id}`">
					{{ t('dossiq', 'The hours leaf is not available, so time cannot be recorded here.') }}
				</p>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
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
		}
	},

	computed: {
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
			return (this.humaniqPresent && this.hoursLeaf !== undefined)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

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

				const states = {}
				for (const item of items) {
					states[item.id] = await fetchReadState(item.subjectId, 'dossiq', item.subjectType)
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
			await recordUpdateOn(item, String(this.updates[item.id] ?? ''))
			this.updates = { ...this.updates, [item.id]: '' }
		},
	},
}
</script>

<style scoped>
.end-of-day {
	padding: 16px;
}

.end-of-day__item {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.end-of-day__item-title {
	margin: 0;
}

.end-of-day__empty,
.end-of-day__no-leaf {
	color: var(--color-text-maxcontrast);
}
</style>
