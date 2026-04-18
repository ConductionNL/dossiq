<!-- SPDX-License-Identifier: EUPL-1.2 -->

<template>
	<div class="advies-panel">
		<div class="advies-panel__header">
			<h3>{{ t(appName, 'Advice Requests') }}</h3>
			<CnButton
				v-if="!isReadOnly"
				icon="plus"
				:disabled="loading"
				@click="showCreateDialog = true">
				{{ t(appName, 'Request advice') }}
			</CnButton>
		</div>

		<CnLoadingIcon v-if="loading" />

		<CnEmptyState
			v-if="!loading && advice.length === 0"
			icon="comment-question-outline">
			{{ t(appName, 'No advice requests') }}
		</CnEmptyState>

		<div v-if="!loading && advice.length > 0" class="advies-panel__list">
			<div
				v-for="item in advice"
				:key="item.id || item.uuid"
				class="advies-panel__row"
				:class="{ 'advies-panel__row--overdue': isOverdue(item) }">
				<div class="advies-panel__content">
					<div class="advies-panel__meta">
						<span class="advies-panel__adviseur">{{ item.adviseur }}</span>
						<CnStatusBadge
							:type="item.type"
							:label="item.type === 'intern' ? t(appName, 'Internal') : t(appName, 'External')">
							{{ item.type === 'intern' ? t(appName, 'Internal') : t(appName, 'External') }}
						</CnStatusBadge>
						<CnStatusBadge
							:type="statusType(item.status)"
							:label="statusLabel(item.status)">
							{{ statusLabel(item.status) }}
						</CnStatusBadge>
					</div>
					<p v-if="item.onderwerp" class="advies-panel__subject">
						{{ item.onderwerp }}
					</p>
					<div v-if="item.deadline" class="advies-panel__deadline" :class="{ 'advies-panel__deadline--overdue': isOverdue(item) }">
						<template v-if="isOverdue(item)">
							{{ t(appName, '{days} days overdue', { days: Math.abs(daysToDeadline(item)) }) }}
						</template>
						<template v-else>
							{{ t(appName, 'Due: {date}', { date: formatDate(item.deadline) }) }}
						</template>
					</div>
				</div>

				<CnRowActions v-if="!isReadOnly">
					<CnRowAction
						v-if="item.status === 'aangevraagd'"
						icon="mail"
						@click="sendReminder(item)">
						{{ t(appName, 'Send reminder') }}
					</CnRowAction>
					<CnRowAction
						v-if="item.status === 'ontvangen' && item.adviesDocument"
						icon="file-document"
						@click="viewDocument(item)">
						{{ t(appName, 'View document') }}
					</CnRowAction>
					<CnRowAction
						v-if="item.status === 'aangevraagd' && item.adviesDocument"
						icon="check-circle"
						@click="markReceived(item)">
						{{ t(appName, 'Mark received') }}
					</CnRowAction>
				</CnRowActions>
			</div>
		</div>

		<!-- Create advice dialog -->
		<AdviesAanvraagDialog
			v-if="showCreateDialog"
			:case-id="caseId"
			@created="onAdviceCreated"
			@closed="showCreateDialog = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	CnButton,
	CnLoadingIcon,
	CnEmptyState,
	CnStatusBadge,
	CnRowActions,
	CnRowAction,
} from '@conduction/nextcloud-vue'
import { NcDialog } from '@nextcloud/vue'
import * as adviceApi from '../../../services/adviceApi.js'
import AdviesAanvraagDialog from './AdviesAanvraagDialog.vue'

const appName = 'procest'

export default {
	name: 'AdviesPanel',

	components: {
		CnButton,
		CnLoadingIcon,
		CnEmptyState,
		CnStatusBadge,
		CnRowActions,
		CnRowAction,
		AdviesAanvraagDialog,
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
			appName,
			advice: [],
			loading: false,
			showCreateDialog: false,
		}
	},

	watch: {
		caseId: {
			immediate: true,
			handler(newCaseId) {
				if (newCaseId) {
					this.fetchAdvice()
				}
			},
		},
	},

	methods: {
		t,

		async fetchAdvice() {
			this.loading = true
			try {
				const response = await adviceApi.getAdviceForCase(this.caseId)
				this.advice = response.results || response || []
			} catch (error) {
				console.error('Failed to fetch advice:', error)
				this.showError(t(appName, 'Failed to load advice requests'))
			} finally {
				this.loading = false
			}
		},

		isOverdue(item) {
			if (item.status !== 'aangevraagd' || !item.deadline) {
				return false
			}
			const deadline = new Date(item.deadline)
			return deadline < new Date()
		},

		daysToDeadline(item) {
			const deadline = new Date(item.deadline)
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			deadline.setHours(0, 0, 0, 0)
			const diff = Math.floor((deadline - today) / (1000 * 60 * 60 * 24))
			return diff
		},

		formatDate(dateString) {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleDateString('nl-NL')
		},

		statusLabel(status) {
			switch (status) {
				case 'aangevraagd':
					return t(appName, 'Requested')
				case 'ontvangen':
					return t(appName, 'Received')
				case 'verlopen':
					return t(appName, 'Expired')
				default:
					return status
			}
		},

		statusType(status) {
			switch (status) {
				case 'aangevraagd':
					return 'info'
				case 'ontvangen':
					return 'success'
				case 'verlopen':
					return 'error'
				default:
					return 'default'
			}
		},

		async sendReminder(item) {
			try {
				await adviceApi.sendReminder(item.id || item.uuid)
				this.showSuccess(t(appName, 'Reminder sent'))
			} catch (error) {
				console.error('Failed to send reminder:', error)
				this.showError(t(appName, 'Failed to send reminder'))
			}
		},

		async markReceived(item) {
			try {
				await adviceApi.updateAdvice(item.id || item.uuid, { status: 'ontvangen' })
				await this.fetchAdvice()
				this.showSuccess(t(appName, 'Advice marked as received'))
			} catch (error) {
				console.error('Failed to mark advice as received:', error)
				this.showError(t(appName, 'Failed to update advice'))
			}
		},

		viewDocument(item) {
			if (item.adviesDocument) {
				window.open(item.adviesDocument, '_blank')
			}
		},

		onAdviceCreated() {
			this.showCreateDialog = false
			this.fetchAdvice()
		},

		showError(message) {
			NcDialog({
				title: t(appName, 'Error'),
				text: message,
				buttons: [
					{
						label: t(appName, 'Close'),
						type: 'primary',
					},
				],
			})
		},

		showSuccess(message) {
			this.$notify({
				title: t(appName, 'Success'),
				text: message,
				type: 'success',
			})
		},
	},
}
</script>

<style scoped>
.advies-panel {
	padding: 16px;
	background: var(--color-background-secondary);
	border-radius: 8px;
}

.advies-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.advies-panel__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.advies-panel__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.advies-panel__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 12px;
	background: var(--color-background-default);
	border: 1px solid var(--color-border-dark);
	border-radius: 4px;
}

.advies-panel__row--overdue {
	background-color: rgba(255, 0, 0, 0.05);
	border-color: var(--color-error);
}

.advies-panel__content {
	flex: 1;
}

.advies-panel__meta {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 4px;
	font-size: 12px;
}

.advies-panel__adviseur {
	font-weight: 600;
	color: var(--color-text);
}

.advies-panel__subject {
	margin: 4px 0;
	color: var(--color-text);
	font-size: 14px;
}

.advies-panel__deadline {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: 4px;
}

.advies-panel__deadline--overdue {
	color: var(--color-error);
	font-weight: 600;
}
</style>
