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
	<div class="case-steps" data-testid="case-steps">
		<NcLoadingIcon v-if="loading" :size="24" />

		<CnTimelineStages
			v-else-if="stages.length > 0"
			:stages="stages"
			:currentStage="currentStage"
			:aria-label="t('dossiq', 'Case progress')"
			orientation="horizontal"
			size="small" />

		<p v-else class="case-steps__empty">
			{{ t('dossiq', 'This case type has no statuses yet') }}
		</p>
	</div>
</template>

<script>
import { CnTimelineStages } from '@conduction/nextcloud-vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { toStages } from '../../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseStepsWidget',

	components: { CnTimelineStages, NcLoadingIcon },

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
				const caseObject = (await store.fetchObject('case', this.caseId)) || {}
				const caseTypeId = String(caseObject.caseType ?? '')
				if (!caseTypeId) {
					this.stages = []
					return
				}
				const rows = await store.fetchCollection('statusType', {
					caseType: caseTypeId,
					_limit: 100,
				})
				this.stages = toStages(rows || [])
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
.case-steps {
	display: flex;
	align-items: center;
	height: 100%;
	padding: 8px 12px;
	overflow-x: auto;
}

.case-steps__empty {
	color: var(--color-text-maxcontrast);
}
</style>
