<template>
	<div class="enforcement-panel">
		<div class="enforcement-panel__header">
			<h3>{{ t('procest', 'Enforcement') }}</h3>
			<NcButton v-if="!isReadOnly" type="primary" @click="showWizard = true">
				{{ t('procest', 'Start enforcement') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<!-- Active enforcement summary -->
		<div v-if="!loading && activeAction" class="enforcement-panel__active">
			<div class="enforcement-panel__classification">
				<span class="enforcement-panel__label">{{
					t('procest', 'Classification:')
				}}</span>
				<span
					class="enforcement-panel__badge enforcement-panel__badge--ernst">
					{{ activeAction.ernst }}
				</span>
				<span
					class="enforcement-panel__badge enforcement-panel__badge--gedrag">
					{{ activeAction.gedrag }}
				</span>
			</div>
			<div class="enforcement-panel__intervention">
				<span class="enforcement-panel__label">{{
					t('procest', 'Intervention:')
				}}</span>
				<strong>{{ activeAction.intervention }}</strong>
			</div>
			<div class="enforcement-panel__status-line">
				<span class="enforcement-panel__label">{{
					t('procest', 'Status:')
				}}</span>
				<span
					class="enforcement-panel__status-badge"
					:class="
						'enforcement-panel__status-badge--' + activeAction.status
					">
					{{ statusLabel(activeAction.status) }}
				</span>
			</div>

			<!-- Dwangsom details -->
			<div
				v-if="activeAction.penaltyPaymentAmount"
				class="enforcement-panel__dwangsom">
				<p>
					<strong>{{ t('procest', 'Penalty:') }}</strong>
					EUR {{ activeAction.penaltyPaymentAmount }}
					{{ t('procest', 'per violation') }} ({{
						t('procest', 'max')
					}}
					EUR {{ activeAction.penaltyPaymentMaximum }})
				</p>
				<p v-if="activeAction.compliance_period">
					<strong>{{ t('procest', 'Grace period:') }}</strong>
					{{ activeAction.compliance_period }}
					{{ t('procest', 'days') }}
				</p>
				<p v-if="totalVerbeurd > 0">
					<strong>{{ t('procest', 'Total forfeited:') }}</strong>
					EUR {{ totalVerbeurd }}
				</p>
			</div>
		</div>

		<!-- Action history -->
		<div
			v-if="!loading && actions.length > 0"
			class="enforcement-panel__history">
			<h4>{{ t('procest', 'Enforcement history') }}</h4>
			<div
				v-for="action in actions"
				:key="action.id"
				class="enforcement-panel__history-item">
				<span
					class="enforcement-panel__status-badge"
					:class="'enforcement-panel__status-badge--' + action.status">
					{{ statusLabel(action.status) }}
				</span>
				<span>{{ action.intervention }}</span>
				<span class="enforcement-panel__history-date"
					>{{ action.ernst }} / {{ action.gedrag }}</span
				>
			</div>
		</div>

		<p v-if="!loading && actions.length === 0" class="enforcement-panel__empty">
			{{ t('procest', 'No enforcement actions yet.') }}
		</p>

		<!-- Enforcement wizard dialog -->
		<EnforcementWizard
			v-if="showWizard"
			:caseId="caseId"
			@close="showWizard = false"
			@created="onActionCreated" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import EnforcementWizard from './EnforcementWizard.vue'
import { useEnforcementStore } from '../../../store/modules/enforcement.js'

export default {
	name: 'EnforcementPanel',

	components: {
		NcButton,
		NcLoadingIcon,
		EnforcementWizard,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		caseTypeId: {
			type: String,
			default: null,
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			showWizard: false,
		}
	},

	computed: {
		/** @spec openspec/specs/vth-module/spec.md */
		enforcementStore() {
			return useEnforcementStore()
		},

		/** @spec openspec/specs/vth-module/spec.md */
		actions() {
			return this.enforcementStore.actions
		},

		/** @spec openspec/specs/vth-module/spec.md */
		activeAction() {
			return this.enforcementStore.activeAction
		},

		/** @spec openspec/specs/vth-module/spec.md */
		totalVerbeurd() {
			return this.enforcementStore.totalVerbeurd
		},

		/** @spec openspec/specs/vth-module/spec.md */
		loading() {
			return this.enforcementStore.loading
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * @param newId
			 * @spec openspec/specs/vth-module/spec.md
			 */
			handler(newId) {
				if (newId) {
					this.enforcementStore.fetchActions(newId)
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * @param status
		 * @spec openspec/specs/vth-module/spec.md
		 */
		statusLabel(status) {
			const labels = {
				opgelegd: t('procest', 'Imposed'),
				verbeurd: t('procest', 'Forfeited'),
				geeffectueerd: t('procest', 'Executed'),
				withdrawn: t('procest', 'Withdrawn'),
			}
			return labels[status] || status
		},

		/** @spec openspec/specs/vth-module/spec.md */
		onActionCreated() {
			this.enforcementStore.fetchActions(this.caseId)
		},
	},
}
</script>

<style scoped>
.enforcement-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
}

.enforcement-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.enforcement-panel__active {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.enforcement-panel__classification,
.enforcement-panel__intervention,
.enforcement-panel__status-line {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.enforcement-panel__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	min-width: 90px;
}

.enforcement-panel__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
}

.enforcement-panel__badge--ernst {
	background: var(--color-warning);
	color: white;
}

.enforcement-panel__badge--gedrag {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.enforcement-panel__status-badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	font-weight: bold;
}

.enforcement-panel__status-badge--opgelegd {
	background: var(--color-warning);
	color: white;
}

.enforcement-panel__status-badge--verbeurd {
	background: var(--color-error);
	color: white;
}

.enforcement-panel__status-badge--geeffectueerd {
	background: var(--color-success);
	color: white;
}

.enforcement-panel__status-badge--ingetrokken {
	background: var(--color-text-maxcontrast);
	color: white;
}

.enforcement-panel__dwangsom {
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.enforcement-panel__dwangsom p {
	margin: 4px 0;
	font-size: 13px;
}

.enforcement-panel__history-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.enforcement-panel__history-date {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-left: auto;
}

.enforcement-panel__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}
</style>
