<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - One page holding everything waiting on the reader: the library's
  - CnWorkQueue over dossiq's personal-queue endpoint.
  -
  - 🔴 THIS COMPONENT NAMES NO SOURCE. Every group, its heading and the
  - sentence saying what closes it come from the server, which builds them from
  - the declared sources. That is the property the whole change is for: the day
  - somebody adds a ninth mechanism, this file does not change, and there is no
  - place in it where a mechanism could be forgotten.
  -
  - A source that could not be read is named at the top by the work queue. It
  - is not folded into the empty state, because "nothing is waiting on you" and
  - "we could not ask" are different sentences and only one of them is a good
  - morning.
  -
  - @visual exclude Every row on this screen is seeded work belonging to the signed-in person, so a baseline would capture one run's fixture data and diff against the next run's. The layout it would guard is a heading, a list and one button per group; what is worth asserting is WHICH rows appear and what closes them, and tests/e2e/one-personal-queue.spec.ts asserts exactly that.
-->
<template>
	<div class="personal-queue">
		<p class="personal-queue__crumbs">
			<router-link :to="{ name: 'MyWorkHome' }">
				{{ t('dossiq', 'My work') }}
			</router-link>
			<span aria-hidden="true">/</span>
			<span>{{ t('dossiq', 'Your queue') }}</span>
		</p>

		<CnWorkQueue :title="t('dossiq', 'Your queue')" :content="queueConfig">
			<template #actions>
				<NcButton variant="secondary" @click="planning = true">
					<template #icon>
						<CalendarClockOutline :size="20" />
					</template>
					{{ t('dossiq', 'Plan an item') }}
				</NcButton>
				<CnBuildiqEditButton />
			</template>
		</CnWorkQueue>

		<!-- Mounted by `v-if`, never by an `open` prop: a dialog whose own
		     `open` defaults to false renders nothing at all and says nothing
		     about why. The queue reads again on the page refresh it emits. -->
		<CnActionDialog
			v-if="planning"
			:config="planConfig"
			@close="planning = false"
			@done="planning = false" />
	</div>
</template>

<script>
import { CnActionDialog, CnBuildiqEditButton, CnWorkQueue } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import { PLAN_ITEM_DIALOG } from './planItemDialog.js'

/** The queue endpoint and its hide/show routes, under /apps/dossiq. */
const BASE = '/apps/dossiq/api/personal-queue'

export default {
	name: 'PersonalQueueView',

	components: {
		CalendarClockOutline,
		CnActionDialog,
		CnBuildiqEditButton,
		CnWorkQueue,
		NcButton,
	},

	data() {
		return {
			planning: false,
		}
	},

	computed: {
		/**
		 * @return {object} The work queue's config: the server's groups, and a group hidden until tomorrow through the server.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		queueConfig() {
			return {
				source: { url: BASE },
				hide: `${BASE}/groups/{group}/hide`,
				show: `${BASE}/groups/{group}/show`,
				emptyText: 'Nothing is waiting on you.',
			}
		},

		/**
		 * @return {object} The plan-an-item dialog's config.
		 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
		 */
		planConfig() {
			return PLAN_ITEM_DIALOG
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.personal-queue {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px 28px;
}

.personal-queue__crumbs {
	display: flex;
	gap: 8px;
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
