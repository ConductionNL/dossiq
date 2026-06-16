<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Dashboard header buttons (New Case + Refresh), wired as the Dashboard
	page's actionsComponent in the manifest. Reuses the existing
	CaseCreateDialog; after creation the user lands on the new case.
-->
<template>
	<div class="dashboard-header-actions">
		<NcButton type="primary" @click="showCaseDialog = true">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('procest', 'New Case') }}
		</NcButton>
		<NcButton
			:aria-label="t('procest', 'Refresh dashboard')"
			@click="refresh">
			<template #icon>
				<Refresh :size="20" :class="{ 'icon-spinning': refreshing }" />
			</template>
		</NcButton>

		<CaseCreateDialog
			v-if="showCaseDialog"
			@created="onCaseCreated"
			@close="showCaseDialog = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CaseCreateDialog from '../cases/CaseCreateDialog.vue'
import {
	refreshDashboardData,
	getCases,
	getCaseTypes,
	getStatusTypes,
	getMyTasks,
} from '../../services/dashboardData.js'

export default {
	name: 'DashboardHeaderActions',
	components: {
		NcButton,
		Plus,
		Refresh,
		CaseCreateDialog,
	},
	data() {
		return {
			showCaseDialog: false,
			refreshing: false,
		}
	},
	methods: {
		/**
		 * Drop cached datasets and bump the refresh signal so every mounted
		 * widget re-runs its load(). Await the shared fetchers so the
		 * spinner reflects the real fetch time — widgets share these
		 * promises, so no duplicate requests.
		 */
		async refresh() {
			this.refreshing = true
			try {
				refreshDashboardData()
				await Promise.allSettled([
					getCases(),
					getCaseTypes(),
					getStatusTypes(),
					getMyTasks(),
				])
			} finally {
				this.refreshing = false
			}
		},
		/**
		 * Navigate to the freshly created case and refresh the widgets.
		 *
		 * @param {string|number} caseId Id of the created case.
		 */
		onCaseCreated(caseId) {
			this.showCaseDialog = false
			refreshDashboardData()
			if (this.$router && caseId) {
				this.$router.push({ path: `/cases/${caseId}` })
			}
		},
	},
}
</script>

<style scoped>
.dashboard-header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.icon-spinning {
	animation: dashboard-refresh-spin 1s linear infinite;
}

@keyframes dashboard-refresh-spin {
	from {
		transform: rotate(0deg);
	}

	to {
		transform: rotate(360deg);
	}
}
</style>
