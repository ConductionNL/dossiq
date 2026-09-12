<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Which step the case is in, over the case type's own status types.

  It replaces the milestone progress tile, which read 0% on every case
  because milestones are configured on almost none — an honest number that
  told a handler nothing. The statuses are configured on every case type,
  because a case cannot exist without one, so this tile has something to say
  about every case.

  The stepper is a `custom` widget rather than a declared one because the
  manifest vocabulary has no `stepper` type over a reference field's ordered
  rows; that request is tasks 3.3, and this is its interim.

  @spec openspec/specs/case-dashboard-view/spec.md
-->
<template>
	<!-- The same card chrome every other widget on the page has: icon, bold
	     title, a rule, and the Actions menu. A custom widget is a consumer slot,
	     so CnDetailPage cannot wrap it itself; with `showTitle` it drew a bare
	     grid heading instead, and the timeline sat under a loose "Timeline"
	     line beside cards that had headers. -->
	<CnWidgetWrapper
		:title="t('dossiq', 'Timeline')"
		widgetId="case-steps"
		titleIconPosition="left"
		:showRefresh="false"
		class="case-steps-card">
		<template #title-icon>
			<Timeline :size="20" />
		</template>
		<div class="case-steps" data-testid="case-steps">
			<NcLoadingIcon v-if="loading" :size="24" />

			<!-- Vertical. A status name is a phrase, not a word — "Melding wordt
		     afgehandeld door beheerdienst" — and laid out left to right those
		     phrases wrap into stacked columns of text with a track line running
		     between them, which reads as a table rather than a sequence. Down
		     the page each stage gets a full line and the track reads as one
		     spine. The grid rows are content-sized, so the taller tile simply
		     takes the height it needs. -->
			<CnTimelineStages
				v-else-if="stages.length > 0"
				:stages="stages"
				:currentStage="currentStage"
				:ariaLabel="t('dossiq', 'Case progress')"
				orientation="vertical"
				size="small" />

			<p v-else class="case-steps__empty">
				{{ t('dossiq', 'This case type has no statuses yet') }}
			</p>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnTimelineStages, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Timeline from 'vue-material-design-icons/Timeline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { toStages } from '../../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseStepsWidget',

	components: { CnTimelineStages, CnWidgetWrapper, NcLoadingIcon, Timeline },

	data() {
		return {
			loading: true,
			stages: [],
			currentStage: null,
		}
	},

	computed: {
		/** @spec openspec/specs/case-dashboard-view/spec.md */
		caseId() {
			return String(this.$route?.params?.id ?? '')
		},
	},

	/**
	 * Read the case once the widget is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/case-dashboard-view/spec.md
	 */
	async mounted() {
		// A transition anywhere on the page moves this stepper: the widget that
		// took it bumps the page refresh signal, and the stepper re-reads the
		// case rather than the handler re-reading the page.
		subscribe(PAGE_REFRESH, this.load)
		await initializeStores()
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.load)
	},

	methods: {
		t,

		/**
		 * Read the case, then its type's statuses, in order.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/case-dashboard-view/spec.md
		 */
		async load() {
			if (!this.caseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const store = useObjectStore()
				const caseObject =
					(await store.fetchObject('case', this.caseId)) || {}
				const caseTypeId = String(caseObject.caseType ?? '')
				if (!caseTypeId) {
					this.stages = []
					return
				}
				// 🔴 THE BLUEPRINT, NOT THE STORE. This asked for
				// `statusType where caseType = X`, which is the case type's
				// OWN rows: a type that derives its lifecycle from a parent
				// has none, so the stepper said "This case type has no
				// statuses yet" about a case type that plainly has four.
				// /blueprint merges the chain server-side and marks each row
				// with where it came from.
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case-types/${encodeURIComponent(caseTypeId)}/blueprint`,
					),
				)
				this.stages = toStages(data?.statusTypes || [])
				this.currentStage = String(caseObject.status ?? '') || null
			} catch {
				// An unreadable case type says "no statuses yet" rather than
				// showing a stepper with nothing in it.
				this.stages = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.case-steps-card {
	height: 100%;
}

/* The wrapper's content region carries the padding now; the 8px 12px that
   was here sat inside it and doubled up. */
.case-steps {
	display: flex;
	align-items: center;
	height: 100%;
	overflow-x: auto;
}

.case-steps__empty {
	color: var(--color-text-maxcontrast);
}
</style>
