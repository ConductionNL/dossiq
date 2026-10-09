<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The Portal section of the case type editor (design D3 of
  portal-citizen-writes-on-the-case).

  What a case type opens to its applicant in the portal: the answers they may
  still change and in which statuses, the amendment and document windows, and
  the withdrawal with the status it lands on. portaliq reads these four
  properties and nothing else, so this is where an administrator decides what
  a resident may do on their own case.

  WHY IT IS CUSTOM. Every picker here offers the case type's OWN statuses,
  inherited ones included, which only `/api/case-types/{id}/blueprint` answers
  (the reason case-type-blueprint is custom too). A declared form would offer a
  free text field for a status uuid.

  The save goes through OpenRegister's object API, where dossiq's pre-save
  guard refuses a withdrawal the workflow cannot write. Its sentence is shown
  as it comes back.

  @spec openspec/changes/portal-citizen-writes-on-the-case/specs/portal-contribution/spec.md
-->
<template>
	<div class="case-type-portal" data-testid="case-type-portal">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="case-type-portal__empty">
			{{
				t(
					'dossiq',
					'The portal settings of this case type could not be read.',
				)
			}}
		</p>
		<template v-else>
			<p class="case-type-portal__intro">
				{{
					t(
						'dossiq',
						'What the applicant may still do on their own case in the portal. Nothing is open until you open it here.',
					)
				}}
			</p>

			<section class="case-type-portal__section">
				<h4>{{ t('dossiq', 'Answers the applicant may change') }}</h4>
				<div
					v-for="field in fields"
					:key="field"
					class="case-type-portal__field">
					<NcCheckboxRadioSwitch
						:modelValue="state.writable[field].enabled"
						:data-testid="`case-type-portal-writable-${field}`"
						@update:modelValue="
							(v) => (state.writable[field].enabled = v)
						">
						{{ fieldLabel(field) }}
					</NcCheckboxRadioSwitch>
					<NcSelect
						v-if="state.writable[field].enabled"
						:modelValue="optionsFor(state.writable[field].openStatuses)"
						:options="statusOptions"
						:multiple="true"
						:inputLabel="t('dossiq', 'Open in these statuses')"
						@update:modelValue="
							(v) => (state.writable[field].openStatuses = idsOf(v))
						" />
				</div>
			</section>

			<section
				v-for="win in windows"
				:key="win.key"
				class="case-type-portal__section">
				<h4>{{ win.title }}</h4>
				<NcSelect
					:modelValue="optionsFor(state[win.key].openStatuses)"
					:options="statusOptions"
					:multiple="true"
					:inputLabel="t('dossiq', 'Open in these statuses')"
					:data-testid="`case-type-portal-${win.key}`"
					@update:modelValue="
						(v) => (state[win.key].openStatuses = idsOf(v))
					" />
				<NcTextField
					:modelValue="state[win.key].closedReason"
					:label="t('dossiq', 'Sentence when closed')"
					@update:modelValue="(v) => (state[win.key].closedReason = v)" />
			</section>

			<section class="case-type-portal__section">
				<h4>{{ t('dossiq', 'Withdrawal from the portal') }}</h4>
				<NcCheckboxRadioSwitch
					:modelValue="state.withdrawal.enabled"
					data-testid="case-type-portal-withdrawal"
					@update:modelValue="(v) => (state.withdrawal.enabled = v)">
					{{ t('dossiq', 'The applicant may withdraw the case') }}
				</NcCheckboxRadioSwitch>
				<template v-if="state.withdrawal.enabled">
					<NcSelect
						:modelValue="optionsFor(state.withdrawal.openStatuses)"
						:options="statusOptions"
						:multiple="true"
						:inputLabel="t('dossiq', 'Open in these statuses')"
						@update:modelValue="
							(v) => (state.withdrawal.openStatuses = idsOf(v))
						" />
					<NcSelect
						:modelValue="
							optionsFor([state.withdrawal.targetStatus])[0] || null
						"
						:options="statusOptions"
						:inputLabel="t('dossiq', 'Status after withdrawal')"
						@update:modelValue="
							(v) => (state.withdrawal.targetStatus = v ? v.id : '')
						" />
					<NcTextField
						:modelValue="state.withdrawal.confirmText"
						:label="t('dossiq', 'Confirmation text')"
						@update:modelValue="
							(v) => (state.withdrawal.confirmText = v)
						" />
					<NcTextField
						:modelValue="state.withdrawal.closedReason"
						:label="t('dossiq', 'Sentence when closed')"
						@update:modelValue="
							(v) => (state.withdrawal.closedReason = v)
						" />
				</template>
			</section>

			<p v-if="refusal" class="case-type-portal__refusal" role="alert">
				{{ refusal }}
			</p>

			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="case-type-portal-save"
				@click="save">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	portalStateFrom,
	readCaseType,
	refusalSentence,
	savePortalSettings,
	WRITABLE_CEILING,
} from '../../services/caseTypePortalSettings.js'

export default {
	name: 'CaseTypePortalWidget',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			error: false,
			saving: false,
			refusal: '',
			statuses: [],
			state: portalStateFrom({}),
		}
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/** @return {string[]} The fields the applicant may be allowed to change. */
		fields() {
			return WRITABLE_CEILING
		},

		/** @return {Array<{key: string, title: string}>} The two windows. */
		windows() {
			return [
				{ key: 'amendment', title: t('dossiq', 'Amendment window') },
				{ key: 'documents', title: t('dossiq', 'Document window') },
			]
		},

		/** @return {Array<{id: string, label: string}>} The type's statuses as options. */
		statusOptions() {
			return this.statuses
				.map((row) => ({
					id: String(row.id ?? row.uuid ?? ''),
					label: String(row.name ?? row.title ?? row.id ?? ''),
				}))
				.filter((option) => option.id !== '')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * The label for a field on the ceiling.
		 *
		 * @param {string} field The case field.
		 * @return {string} The label.
		 */
		fieldLabel(field) {
			return field === 'description'
				? t('dossiq', 'Description of the request')
				: field
		},

		/**
		 * The options for a list of status ids, keeping an unknown id visible.
		 *
		 * @param {string[]} ids The ids.
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		optionsFor(ids) {
			return (ids || [])
				.filter((id) => id)
				.map(
					(id) =>
						this.statusOptions.find((option) => option.id === id) ?? {
							id,
							label: id,
						},
				)
		},

		/**
		 * The ids of the chosen options.
		 *
		 * @param {Array<object>|null} options The options.
		 * @return {string[]} The ids.
		 */
		idsOf(options) {
			return (options || []).map((option) => option.id)
		},

		/**
		 * Read the case type and its statuses.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const [caseType, blueprint] = await Promise.all([
					readCaseType(this.caseTypeId),
					axios.get(
						generateUrl(
							`/apps/dossiq/api/case-types/${encodeURIComponent(this.caseTypeId)}/blueprint`,
						),
					),
				])
				this.state = portalStateFrom(caseType)
				this.statuses = blueprint?.data?.statusTypes ?? []
				this.error = false
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write the section, showing the guard's sentence when it refuses.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.refusal = ''
			try {
				await savePortalSettings(this.caseTypeId, this.state)
				showSuccess(t('dossiq', 'Portal settings saved'))
			} catch (error) {
				this.refusal = refusalSentence(error)
				if (this.refusal === '') {
					showError(t('dossiq', 'The portal settings could not be saved.'))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-portal {
	height: 100%;
	padding: 8px 12px;
	overflow-y: auto;
}

.case-type-portal__section {
	margin-block: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.case-type-portal__intro,
.case-type-portal__empty {
	color: var(--color-text-maxcontrast);
}

.case-type-portal__refusal {
	color: var(--color-error-text);
}
</style>
