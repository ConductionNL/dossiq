<template>
	<div class="consultation-settings">
		<NcNoteCard type="info">
			{{
				t(
					'procest',
					'Structured consultation (adviesaanvraag) is being delivered in consultation-management. This panel will host advisory body registry, mandatory-gate config and n8n webhook endpoints.',
				)
			}}
		</NcNoteCard>

		<div class="setting-row">
			<label for="consultation_default_deadline_days">
				{{ t('procest', 'Default deadline (days) for new consultations') }}
			</label>
			<NcInputField
				id="consultation_default_deadline_days"
				v-model="defaultDeadlineDays"
				type="number"
				:disabled="!writable"
				placeholder="28" />
			<p class="setting-help">
				{{
					t(
						'procest',
						'Used when an advisory body has no explicit defaultDeadlineDays configured.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="consultation_warning_offset_days">
				{{ t('procest', 'Warning offset (days before deadline)') }}
			</label>
			<NcInputField
				id="consultation_warning_offset_days"
				v-model="warningOffsetDays"
				type="number"
				:disabled="!writable"
				placeholder="5" />
			<p class="setting-help">
				{{
					t(
						'procest',
						'The deadline-monitor n8n workflow uses this offset to send T-X warnings.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="consultation_external_response_url">
				{{ t('procest', 'External response base URL') }}
			</label>
			<NcInputField
				id="consultation_external_response_url"
				v-model="externalResponseUrl"
				:disabled="!writable"
				placeholder="https://procest.example.org/consultation/respond/" />
			<p class="setting-help">
				{{
					t(
						'procest',
						'Base URL used in secure response links sent to external advisory bodies. Must be HTTPS.',
					)
				}}
			</p>
		</div>

		<div class="setting-row">
			<label for="consultation_bottleneck_threshold">
				{{ t('procest', 'Bottleneck overdue-rate threshold (0-1)') }}
			</label>
			<NcInputField
				id="consultation_bottleneck_threshold"
				v-model="bottleneckThreshold"
				type="number"
				:disabled="!writable"
				placeholder="0.2" />
			<p class="setting-help">
				{{
					t(
						'procest',
						'When an advisory body exceeds this overdue-rate over the trailing 30 days, the bottleneck workflow notifies coordinators.',
					)
				}}
			</p>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton type="primary" :disabled="!writable || saving" @click="save">
			<template #icon>
				<NcLoadingIcon v-if="saving" :size="20" />
			</template>
			{{
				saving
					? t('procest', 'Saving...')
					: t('procest', 'Save consultation settings')
			}}
		</NcButton>

		<p class="docs-link">
			<a :href="workflowDocsUrl" target="_blank" rel="noopener">
				{{
					t('procest', 'Read the n8n consultation workflows documentation')
				}}
			</a>
		</p>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * Consultation management admin settings tab.
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
export default {
	name: 'ConsultationSettingsTab',
	components: { NcButton, NcInputField, NcLoadingIcon, NcNoteCard },
	data() {
		const initial = loadState('procest', 'consultationSettings', {})
		return {
			defaultDeadlineDays: initial.defaultDeadlineDays ?? 28,
			warningOffsetDays: initial.warningOffsetDays ?? 5,
			externalResponseUrl: initial.externalResponseUrl ?? '',
			bottleneckThreshold: initial.bottleneckThreshold ?? 0.2,
			writable: initial.writable ?? true,
			saving: false,
			error: null,
		}
	},

	computed: {
		/** @spec openspec/specs/consultation-management/spec.md */
		workflowDocsUrl() {
			return 'https://docs.procest.nl/n8n-consultation-workflows'
		},
	},

	methods: {
		t,
		/**
		 * Persist the consultation settings.
		 *
		 * ⚠️ This used to POST `/apps/procest/api/settings/consultation`, a route
		 * procest never declared. Nextcloud answers an unmatched app URL with its
		 * own HTML page under HTTP 200, so `fetch` resolved, nothing threw, and
		 * every save silently vanished (procest#794). It now uses the app's own
		 * canonical settings write, which carries
		 * `#[AuthorizedAdminSetting]` — deliberately not OpenRegister's generic
		 * object route, which also works and would bypass that guard.
		 *
		 * `fetch` does NOT reject on a non-2xx response, so `res.ok` has to be
		 * checked explicitly or a 403 reads exactly like a successful save.
		 *
		 * @spec openspec/specs/consultation-management/spec.md
		 */
		async save() {
			this.saving = true
			this.error = null
			try {
				const res = await fetch(generateUrl('/apps/procest/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						consultation_default_deadline_days: String(
							Number(this.defaultDeadlineDays),
						),
						consultation_warning_offset_days: String(
							Number(this.warningOffsetDays),
						),
						consultation_external_response_url: this.externalResponseUrl,
						consultation_bottleneck_threshold: String(
							Number(this.bottleneckThreshold),
						),
					}),
				})
				if (!res.ok) {
					this.error = t('procest', 'Saving failed ({status})', {
						status: res.status,
					})
				}
			} catch (e) {
				this.error = e.message || t('procest', 'Saving failed')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.consultation-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.setting-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.docs-link {
	margin-top: 8px;
	font-size: 0.9em;
}
</style>
