<template>
	<div class="advice-panel">
		<div class="advice-panel__header">
			<h3>{{ t('procest', 'Advice') }}</h3>
			<NcButton v-if="!isReadOnly" @click="showRequestDialog = true">
				{{ t('procest', 'Request advice') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-if="!loading" class="advice-panel__list">
			<div
				v-for="request in requests"
				:key="request.id"
				class="advice-panel__item"
				:class="{ 'advice-panel__item--overdue': isOverdue(request) }">
				<div class="advice-panel__item-header">
					<span class="advice-panel__adviseur">{{
						request.adviseur
					}}</span>
					<span
						class="advice-panel__type-badge"
						:class="'advice-panel__type-badge--' + request.type">
						{{
							request.type === 'intern'
								? t('procest', 'Internal')
								: t('procest', 'External')
						}}
					</span>
					<span
						class="advice-panel__status-badge"
						:class="statusClass(request.status)">
						{{ statusLabel(request.status) }}
					</span>
					<span
						v-if="request.deadline"
						class="advice-panel__deadline"
						:class="{
							'advice-panel__deadline--overdue': isOverdue(request),
						}">
						<template v-if="isOverdue(request)">
							{{
								t('procest', '{days} days overdue', {
									days: Math.abs(getDaysToDeadline(request)),
								})
							}}
						</template>
						<template v-else>
							{{
								t('procest', 'Due: {date}', {
									date: formatDate(request.deadline),
								})
							}}
						</template>
					</span>
				</div>
				<p v-if="request.onderwerp" class="advice-panel__subject">
					{{ request.onderwerp }}
				</p>
				<div v-if="!isReadOnly" class="advice-panel__actions">
					<NcButton
						v-if="request.status === 'aangevraagd'"
						size="small"
						@click="markReceived(request)">
						{{ t('procest', 'Mark received') }}
					</NcButton>
					<NcButton
						v-if="
							request.status === 'ontvangen' && request.adviesDocument
						"
						size="small"
						@click="viewDocument(request)">
						{{ t('procest', 'View advice') }}
					</NcButton>
				</div>
			</div>

			<p v-if="requests.length === 0" class="advice-panel__empty">
				{{ t('procest', 'No advice requests.') }}
			</p>
		</div>

		<!-- Advice request dialog -->
		<div
			v-if="showRequestDialog"
			class="advice-panel__dialog-overlay"
			role="button"
			tabindex="0"
			@click.self="showRequestDialog = false"
			@keydown.enter.self="showRequestDialog = false"
			@keydown.space.self.prevent="showRequestDialog = false">
			<div class="advice-panel__dialog">
				<h4>{{ t('procest', 'Request Advice') }}</h4>

				<div class="advice-panel__field">
					<label>{{ t('procest', 'Type') }}</label>
					<div class="advice-panel__toggle-group">
						<label>
							<input
								v-model="newRequest.type"
								type="radio"
								value="intern" />
							{{ t('procest', 'Internal') }}
						</label>
						<label>
							<input
								v-model="newRequest.type"
								type="radio"
								value="extern" />
							{{ t('procest', 'External') }}
						</label>
					</div>
				</div>

				<div class="advice-panel__field">
					<label for="advice-panel-adviseur">{{
						t('procest', 'Advisor')
					}}</label>
					<input
						id="advice-panel-adviseur"
						v-model="newRequest.adviseur"
						type="text"
						class="advice-panel__input"
						:placeholder="
							newRequest.type === 'intern'
								? t('procest', 'User ID')
								: t('procest', 'Organization name')
						" />
				</div>

				<div class="advice-panel__field">
					<label for="advice-panel-onderwerp">{{
						t('procest', 'Subject')
					}}</label>
					<input
						id="advice-panel-onderwerp"
						v-model="newRequest.onderwerp"
						type="text"
						class="advice-panel__input"
						:placeholder="t('procest', 'What advice is needed?')" />
				</div>

				<div class="advice-panel__field">
					<label for="advice-panel-deadline">{{
						t('procest', 'Deadline')
					}}</label>
					<input
						id="advice-panel-deadline"
						v-model="newRequest.deadline"
						type="date"
						class="advice-panel__input" />
				</div>

				<div class="advice-panel__field">
					<label for="advice-panel-questions">{{
						t('procest', 'Questions')
					}}</label>
					<textarea
						id="advice-panel-questions"
						v-model="newRequest.questions"
						class="advice-panel__textarea"
						rows="3"
						:placeholder="
							t('procest', 'Specific questions for the advisor')
						" />
				</div>

				<div class="advice-panel__dialog-actions">
					<NcButton
						type="primary"
						:disabled="!canSubmit || submitting"
						@click="submitRequest">
						{{
							submitting
								? t('procest', 'Sending...')
								: t('procest', 'Send request')
						}}
					</NcButton>
					<NcButton @click="showRequestDialog = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { useAdviceStore } from '../../../store/modules/advice.js'

export default {
	name: 'AdvicePanel',

	components: {
		NcButton,
		NcLoadingIcon,
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
			showRequestDialog: false,
			submitting: false,
			newRequest: {
				type: 'intern',
				adviseur: '',
				onderwerp: '',
				deadline: this.defaultDeadline(),
				questions: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		adviceStore() {
			return useAdviceStore()
		},
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		requests() {
			return this.adviceStore.requests
		},
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		loading() {
			return this.adviceStore.loading
		},
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		canSubmit() {
			return this.newRequest.adviseur && this.newRequest.deadline
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * @param newId
			 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
			 */
			handler(newId) {
				if (newId) {
					this.adviceStore.fetchRequests(newId)
				}
			},
		},
	},

	methods: {
		t,

		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		defaultDeadline() {
			const d = new Date()
			d.setDate(d.getDate() + 14)
			return d.toISOString().split('T')[0]
		},

		/**
		 * @param request
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		isOverdue(request) {
			if (request.status !== 'aangevraagd' || !request.deadline) {
				return false
			}
			return new Date(request.deadline) < new Date()
		},

		getDaysToDeadline(request) {
			return this.adviceStore.getDaysToDeadline(request)
		},

		/**
		 * @param dateStr
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		formatDate(dateStr) {
			if (!dateStr) {
				return ''
			}
			return new Date(dateStr).toLocaleDateString()
		},

		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		statusLabel(status) {
			const labels = {
				aangevraagd: t('procest', 'Requested'),
				ontvangen: t('procest', 'Received'),
				verlopen: t('procest', 'Expired'),
			}
			return labels[status] || status
		},

		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		statusClass(status) {
			return {
				'advice-panel__status-badge--aangevraagd': status === 'aangevraagd',
				'advice-panel__status-badge--ontvangen': status === 'ontvangen',
				'advice-panel__status-badge--verlopen': status === 'verlopen',
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		async submitRequest() {
			this.submitting = true
			try {
				await this.adviceStore.createRequest({
					case: this.caseId,
					...this.newRequest,
				})
				this.showRequestDialog = false
				this.newRequest = {
					type: 'intern',
					adviseur: '',
					onderwerp: '',
					deadline: this.defaultDeadline(),
					questions: '',
				}
			} finally {
				this.submitting = false
			}
		},

		/**
		 * @param request
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		async markReceived(request) {
			await this.adviceStore.markReceived(request.id)
		},

		/**
		 * @param request
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		viewDocument(request) {
			if (request.adviesDocument) {
				window.open(
					`/apps/files/?fileid=${request.adviesDocument}`,
					'_blank',
				)
			}
		},
	},
}
</script>

<style scoped>
.advice-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
}

.advice-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.advice-panel__item {
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.advice-panel__item--overdue {
	border-color: var(--color-error);
}

.advice-panel__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.advice-panel__adviseur {
	font-weight: bold;
}

.advice-panel__type-badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
}

.advice-panel__type-badge--intern {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.advice-panel__type-badge--extern {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.advice-panel__status-badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	font-weight: bold;
}

.advice-panel__status-badge--aangevraagd {
	background: var(--color-primary-element);
	color: white;
}

.advice-panel__status-badge--ontvangen {
	background: var(--color-success);
	color: white;
}

.advice-panel__status-badge--verlopen {
	background: var(--color-error);
	color: white;
}

.advice-panel__deadline {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

.advice-panel__deadline--overdue {
	color: var(--color-error);
	font-weight: bold;
}

.advice-panel__subject {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.advice-panel__actions {
	margin-top: 6px;
}

.advice-panel__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}

.advice-panel__dialog-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1000;
}

.advice-panel__dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 500px;
	width: 90%;
}

.advice-panel__field {
	margin-bottom: 14px;
}

.advice-panel__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.advice-panel__input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.advice-panel__textarea {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.advice-panel__toggle-group {
	display: flex;
	gap: 16px;
}

.advice-panel__toggle-group label {
	font-weight: normal;
	display: flex;
	align-items: center;
	gap: 4px;
}

.advice-panel__dialog-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
