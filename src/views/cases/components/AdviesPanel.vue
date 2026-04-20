<!-- SPDX-License-Identifier: EUPL-1.2 -->

<template>
	<div class="adviespanel">
		<div class="adviespanel-header">
			<h3>{{ t('procest', 'Advice Requests') }}</h3>
			<NcButton
				v-if="!isReadOnly"
				type="primary"
				size="small"
				@click="showDialog = true">
				{{ t('procest', 'Request Advice') }}
			</NcButton>
		</div>

		<NcEmptyContent
			v-if="advice.length === 0 && !loading"
			:name="t('procest', 'No advice requests')"
			:description="t('procest', 'No advice has been requested yet')">
			<template #icon>
				<CommentQuestionOutline />
			</template>
		</NcEmptyContent>

		<div v-if="loading" class="adviespanel-loading">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-if="!loading && advice.length > 0" class="adviespanel-list">
			<div
				v-for="item in advice"
				:key="item.id"
				class="adviespanel-item"
				:class="{ 'adviespanel-item--overdue': isOverdue(item) }">
				<div class="adviespanel-item-main">
					<div class="adviespanel-item-header">
						<span class="adviespanel-adviseur">{{ item.adviseur }}</span>
						<CnStatusBadge
							:text="item.type === 'intern' ? t('procest', 'Internal') : t('procest', 'External')"
							:type="item.type === 'intern' ? 'info' : 'success'" />
						<CnStatusBadge
							:text="statusLabel(item.status)"
							:type="statusType(item.status)" />
					</div>
					<p v-if="item.onderwerp" class="adviespanel-subject">
						{{ item.onderwerp }}
					</p>
					<div v-if="item.deadline" class="adviespanel-deadline">
						<template v-if="isOverdue(item)">
							<span class="adviespanel-deadline-overdue">
								{{ t('procest', '{days} days overdue', { days: Math.abs(daysToDeadline(item)) }) }}
							</span>
						</template>
						<template v-else>
							{{ formatDate(item.deadline) }}
						</template>
					</div>
				</div>

				<CnRowActions
					v-if="!isReadOnly && canEdit(item)"
					:actions="getActions(item)"
					@action="handleAction" />
			</div>
		</div>

		<AdviesAanvraagDialog
			:visible="showDialog"
			:case-id="caseId"
			@close="showDialog = false"
			@created="onAdviceCreated" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnStatusBadge, CnRowActions } from '@conduction/nextcloud-vue'
import { CommentQuestionOutline } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import * as adviceApi from '../../../services/adviceApi.js'
import AdviesAanvraagDialog from './AdviesAanvraagDialog.vue'

export default {
	name: 'AdviesPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnStatusBadge,
		CnRowActions,
		AdviesAanvraagDialog,
		CommentQuestionOutline,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			advice: [],
			loading: false,
			showDialog: false,
		}
	},

	watch: {
		caseId: {
			immediate: true,
			async handler(newId) {
				if (newId) {
					await this.loadAdvice()
				}
			},
		},
	},

	methods: {
		t,

		async loadAdvice() {
			this.loading = true
			try {
				const response = await adviceApi.getAdviceForCase(this.caseId)
				this.advice = response.results || []
			} catch (error) {
				this.$notify({
					title: t('procest', 'Error'),
					text: t('procest', 'Failed to load advice requests'),
					type: 'error',
				})
			} finally {
				this.loading = false
			}
		},

		isOverdue(item) {
			if (item.status !== 'aangevraagd' || !item.deadline) {
				return false
			}
			return new Date(item.deadline) < new Date()
		},

		daysToDeadline(item) {
			if (!item.deadline) return 0
			const deadline = new Date(item.deadline)
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			deadline.setHours(0, 0, 0, 0)
			const diff = today.getTime() - deadline.getTime()
			return Math.floor(diff / (1000 * 60 * 60 * 24))
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},

		statusLabel(status) {
			const labels = {
				aangevraagd: t('procest', 'Requested'),
				ontvangen: t('procest', 'Received'),
				verlopen: t('procest', 'Expired'),
			}
			return labels[status] || status
		},

		statusType(status) {
			const types = {
				aangevraagd: 'info',
				ontvangen: 'success',
				verlopen: 'error',
			}
			return types[status] || 'info'
		},

		canEdit(item) {
			return !this.isReadOnly
		},

		getActions(item) {
			const actions = []
			if (item.status === 'aangevraagd') {
				actions.push({
					label: t('procest', 'Send reminder'),
					action: 'remind',
				})
			}
			if (item.status === 'ontvangen' && item.adviesDocument) {
				actions.push({
					label: t('procest', 'View advice'),
					action: 'view',
				})
			}
			return actions
		},

		async handleAction(actionType, item) {
			try {
				if (actionType === 'remind') {
					await adviceApi.sendReminder(item.id)
					this.$notify({
						title: t('procest', 'Success'),
						text: t('procest', 'Reminder sent'),
						type: 'success',
					})
				} else if (actionType === 'view') {
					if (item.adviesDocument) {
						window.open(`/apps/files/?fileid=${item.adviesDocument}`, '_blank')
					}
				}
			} catch (error) {
				this.$notify({
					title: t('procest', 'Error'),
					text: t('procest', 'Action failed'),
					type: 'error',
				})
			}
		},

		onAdviceCreated() {
			this.showDialog = false
			this.loadAdvice()
		},
	},
}
</script>

<style scoped>
.adviespanel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
	background: var(--color-main-background);
}

.adviespanel-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
	gap: 12px;
}

.adviespanel-header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.adviespanel-loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.adviespanel-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.adviespanel-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-secondary);
	gap: 12px;
}

.adviespanel-item--overdue {
	border-color: var(--color-error);
	background: rgba(255, 0, 0, 0.05);
}

.adviespanel-item-main {
	flex: 1;
}

.adviespanel-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
	flex-wrap: wrap;
}

.adviespanel-adviseur {
	font-weight: 600;
	flex-shrink: 0;
}

.adviespanel-subject {
	margin: 4px 0;
	font-size: 13px;
	color: var(--color-text-secondary);
}

.adviespanel-deadline {
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-text-secondary);
}

.adviespanel-deadline-overdue {
	color: var(--color-error);
	font-weight: 600;
}
</style>
