<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Dashboard header buttons (New Case + Refresh), wired as the Dashboard
	page's actionsComponent in the manifest. "New Case" routes to the generic
	Cases index page with a `?action=create` deep-link, which opens the
	schema-driven CnFormDialog there — the single, generic create path for
	cases (no bespoke create dialog).
-->
<template>
	<div class="dashboard-header-actions">
		<NcButton type="primary" @click="newCase">
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
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
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
	},
	data() {
		return {
			refreshing: false,
		}
	},
	methods: {
		/**
		 * Open the generic case-create form. Routes to the Cases index page
		 * with a `?action=create` deep-link; CnIndexPage opens its built-in
		 * schema-driven CnFormDialog on arrival. This keeps a single, generic
		 * create path — identifier, deadline and status are filled
		 * declaratively by OpenRegister, so there is no bespoke create form.
		 */
		newCase() {
			if (this.$router) {
				this.$router.push({ name: 'Cases', query: { action: 'create' } })
					.catch(() => {})
			}
		},
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
