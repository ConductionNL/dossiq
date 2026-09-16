<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - One page holding everything waiting on the reader.
  -
  - 🔴 THIS COMPONENT NAMES NO SOURCE. Every group, its heading and the
  - sentence saying what closes it come from the server, which builds them from
  - the declared sources. That is the property the whole change is for: the day
  - somebody adds a ninth mechanism, this file does not change, and there is no
  - place in it where a mechanism could be forgotten.
  -
  - A source that could not be read is named at the top. It is not folded into
  - the empty state, because "nothing is waiting on you" and "we could not ask"
  - are different sentences and only one of them is a good morning.
  -
  - @visual exclude Every row on this screen is seeded work belonging to the signed-in person, so a baseline would capture one run's fixture data and diff against the next run's. The layout it would guard is a heading, a list and one button per group; what is worth asserting is WHICH rows appear and what closes them, and tests/e2e/one-personal-queue.spec.ts asserts exactly that.
-->
<template>
	<div class="personal-queue">
		<div class="personal-queue__head">
			<h2 class="personal-queue__title">
				{{ t('dossiq', 'Your queue') }}
			</h2>
			<NcButton variant="secondary" @click="openPlanner">
				{{ t('dossiq', 'Plan an item') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-for="source in unavailable"
			:key="source.source"
			type="warning"
			:data-testid="`queue-source-unavailable-${source.source}`">
			{{
				t(
					'dossiq',
					'{source} could not be read, so this list is incomplete.',
					{ source: source.label },
				)
			}}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<p
				v-if="!groups.length"
				class="personal-queue__empty"
				data-testid="queue-empty">
				{{ t('dossiq', 'Nothing is waiting on you.') }}
			</p>

			<section
				v-for="group in groups"
				:key="group.key"
				class="personal-queue__group"
				:data-testid="`queue-group-${group.key}`">
				<div class="personal-queue__group-head">
					<h3 class="personal-queue__group-title">
						{{ group.label }}
					</h3>
					<NcButton
						variant="tertiary"
						:aria-label="t('dossiq', 'Hide this group until tomorrow')"
						@click="hideGroup(group.key)">
						{{ t('dossiq', 'Hide until tomorrow') }}
					</NcButton>
				</div>
				<p class="personal-queue__closes">
					{{ group.closesWhen }}
				</p>
				<ul class="personal-queue__items">
					<li
						v-for="item in group.items"
						:key="item.id"
						class="personal-queue__item"
						:class="{
							'personal-queue__item--late': item.tier === 'overdue',
						}"
						:data-testid="`queue-item-${item.id}`">
						<a
							href="#"
							class="personal-queue__link"
							@click.prevent="open(item)">
							{{ item.title }}
						</a>
						<span v-if="item.covered" class="personal-queue__covered">
							{{
								t('dossiq', 'Covering for {person}', {
									person: item.coveredFor,
								})
							}}
						</span>
						<span v-if="item.dueAt" class="personal-queue__due">{{
							item.dueAt
						}}</span>
					</li>
				</ul>
			</section>
		</template>

		<section v-if="hiddenGroups.length" class="personal-queue__hidden">
			<h3 class="personal-queue__group-title">
				{{ t('dossiq', 'Hidden until tomorrow') }}
			</h3>
			<NcButton
				v-for="group in hiddenGroups"
				:key="group"
				variant="tertiary"
				:data-testid="`queue-hidden-${group}`"
				@click="showGroup(group)">
				{{ t('dossiq', 'Show {group} again', { group }) }}
			</NcButton>
		</section>

		<!-- Mounted by `v-if`, never by an `open` prop: a dialog whose own
		     `open` defaults to false renders nothing at all and says nothing
		     about why. -->
		<PlanItemDialog
			v-if="planning"
			@close="planning = false"
			@planned="onPlanned" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import PlanItemDialog from '../../dialogs/PlanItemDialog.vue'
import {
	fetchQueue,
	hideGroupForToday,
	showGroupAgain,
} from '../../services/personalQueueApi.js'
import { visibleGroups } from '../../utils/personalQueueHelpers.js'

export default {
	name: 'PersonalQueueView',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		PlanItemDialog,
	},

	data() {
		return {
			loading: false,
			allGroups: [],
			hiddenGroups: [],
			unavailable: [],
			planning: false,
		}
	},

	computed: {
		/**
		 * The groups still on screen.
		 *
		 * @return {Array} The groups to render.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		groups() {
			return visibleGroups(this.allGroups, this.hiddenGroups)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the queue.
		 *
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async load() {
			this.loading = true
			try {
				const queue = await fetchQueue()
				this.allGroups = queue.groups
				this.hiddenGroups = queue.hiddenGroups
				this.unavailable = queue.unavailable
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open what an item points at.
		 *
		 * @param {object} item The item.
		 * @return {void}
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		open(item) {
			if (item?.route?.name) {
				this.$router.push(item.route)
			}
		},

		/**
		 * Hide one group until tomorrow.
		 *
		 * @param {string} group The group key.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async hideGroup(group) {
			this.hiddenGroups = await hideGroupForToday(group)
		},

		/**
		 * Show a group again.
		 *
		 * @param {string} group The group key.
		 * @return {Promise<void>} When the write has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async showGroup(group) {
			this.hiddenGroups = await showGroupAgain(group)
		},

		/**
		 * Open the planner.
		 *
		 * @return {void}
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		openPlanner() {
			this.planning = true
		},

		/**
		 * Close the planner and read the queue again.
		 *
		 * @return {Promise<void>} When the read has finished.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		async onPlanned() {
			this.planning = false
			await this.load()
		},
	},
}
</script>

<style scoped>
.personal-queue {
	padding: 16px;
}

.personal-queue__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.personal-queue__group {
	margin-block-start: 24px;
}

.personal-queue__group-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.personal-queue__closes {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.personal-queue__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.personal-queue__item {
	display: flex;
	align-items: baseline;
	gap: 12px;
	flex-wrap: wrap;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.personal-queue__item--late .personal-queue__link {
	color: var(--color-error);
}

.personal-queue__covered,
.personal-queue__due {
	color: var(--color-text-maxcontrast);
}

.personal-queue__empty {
	color: var(--color-text-maxcontrast);
}
</style>
