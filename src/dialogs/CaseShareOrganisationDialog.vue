<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Sharing a case with another organisation, or handing it over (board
  DqZaakSamenwerken).

  One dialog for the two acts a handler chooses between: Share keeps the case
  here and lets the other organisation work along with the permission chosen;
  Hand over makes them the owner once they accept. Share can also leave case
  fields out of what the partner sees, the field selection the remote share
  already offered, so nothing a handler could limit before is lost. Both acts
  go through the endpoints the sharing tab uses: a partner share (with the
  consent gate on the server) and a case transfer.

  @spec openspec/specs/federated-case-collaboration/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Share the case with another organisation')"
		:eyebrow="eyebrow"
		data-testid="case-share-organisation-dialog"
		@closing="$emit('close')">
		<div class="case-share-org">
			<CnChoiceCards
				v-model="mode"
				:options="modes"
				:label="t('dossiq', 'What you do')"
				:hideLabel="true"
				layout="rows"
				:columns="2"
				data-testid="case-share-modes" />

			<NcSelect
				v-model="organisation"
				:options="partners"
				label="label"
				:inputLabel="t('dossiq', 'Organisation')"
				:clearable="false"
				:placeholder="t('dossiq', 'Choose an organisation')"
				data-testid="case-share-organisation" />

			<NcSelect
				v-if="mode === 'share'"
				v-model="permission"
				:options="permissionOptions"
				label="label"
				:clearable="false"
				:inputLabel="t('dossiq', 'What they may do')"
				data-testid="case-share-permission" />

			<div class="case-share-org__field">
				<label class="case-share-org__label" for="case-share-message">
					{{ t('dossiq', 'Message') }}
					<span class="case-share-org__optional">{{ t('dossiq', '(optional)') }}</span>
				</label>
				<NcTextField
					id="case-share-message"
					v-model="message"
					:label="t('dossiq', 'Message')"
					:labelOutside="true"
					data-testid="case-share-message" />
			</div>

			<template v-if="mode === 'share'">
				<NcCheckboxRadioSwitch
					v-model="limitFields"
					data-testid="case-share-limit-fields">
					{{ t('dossiq', 'Share only some case fields') }}
				</NcCheckboxRadioSwitch>
				<fieldset v-if="limitFields" class="case-share-org__fields" data-testid="case-share-fields">
					<legend>{{ t('dossiq', 'Fields they see') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="field in fieldOptions"
						:key="field.value"
						:modelValue="sharedFields.includes(field.value)"
						@update:modelValue="toggleField(field.value, $event)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>
			</template>

			<NcNoteCard type="info">
				{{ mode === 'share'
					? t('dossiq', 'You then see on the case what the other organisation does: opened, document added, returned.')
					: t('dossiq', 'The other organisation becomes the owner once it accepts. Until then the case stays yours.') }}
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error" data-testid="case-share-error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				data-testid="case-share-submit"
				@click="submit">
				<template #icon>
					<Send :size="20" />
				</template>
				{{ mode === 'share' ? t('dossiq', 'Share') : t('dossiq', 'Hand over') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Send from 'vue-material-design-icons/Send.vue'
import { FEDERATION_ALLOWED_FIELDS } from '../utils/federatedShareHelpers.js'
import { loadPartnerOrganisations } from '../utils/partnerOrganisations.js'

export default {
	name: 'CaseShareOrganisationDialog',

	components: {
		CnChoiceCards,
		CnDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Send,
	},

	props: {
		/** The case to share; falls back to the route's id. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	data() {
		const permissionOptions = [
			{ value: 'bekijken', label: t('dossiq', 'Read only') },
			{ value: 'bekijken_reageren', label: t('dossiq', 'Read and comment') },
			{ value: 'bekijken_bijdragen', label: t('dossiq', 'Read and add documents') },
		]
		return {
			/** `share` keeps the case here; `handover` makes the partner the owner. */
			mode: 'share',
			partners: [],
			organisation: null,
			permissionOptions,
			permission: permissionOptions[2],
			message: '',
			/** Whether the share leaves some case fields out. */
			limitFields: false,
			/** The case fields the partner sees when fields are limited. */
			sharedFields: [...FEDERATION_ALLOWED_FIELDS],
			/** The case, for the eyebrow. */
			kase: null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * @return {string} The case id, from the prop or the route.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {string} "<title>, <case number>", as the board heads the dialog.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		eyebrow() {
			return [this.kase?.title, this.kase?.identifier].filter(Boolean).join(', ')
		},

		/**
		 * @return {Array<{value: string, label: string, description: string}>} The two acts.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		modes() {
			return [
				{ value: 'share', label: t('dossiq', 'Share'), description: t('dossiq', 'You stay the owner; they work along') },
				{ value: 'handover', label: t('dossiq', 'Hand over'), description: t('dossiq', 'They become the owner; you can still read along') },
			]
		},

		/**
		 * @return {Array<{value: string, label: string}>} The case fields a share may limit.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		fieldOptions() {
			const labels = {
				title: t('dossiq', 'Title'),
				description: t('dossiq', 'Description'),
				status: t('dossiq', 'Status'),
				caseType: t('dossiq', 'Case type'),
				priority: t('dossiq', 'Priority'),
				dueDate: t('dossiq', 'Deadline'),
				requestedDate: t('dossiq', 'Requested date'),
			}
			return FEDERATION_ALLOWED_FIELDS.map((value) => ({ value, label: labels[value] || value }))
		},

		/**
		 * @return {boolean} Whether the form holds what the chosen act needs.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		canSubmit() {
			if (this.saving || !this.organisation) {
				return false
			}
			return this.mode === 'handover' || !this.limitFields || this.sharedFields.length > 0
		},
	},

	async mounted() {
		await Promise.all([this.loadPartners(), this.loadCase()])
	},

	methods: {
		t,

		/**
		 * Read the partner organisations a case may go to.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
		 * @spec openspec/changes/share-dialog-lists-every-partner/specs/share-dialog-lists-every-partner/spec.md#requirement-the-share-dialog-offers-every-partner-organisation
		 */
		async loadPartners() {
			try {
				this.partners = (await loadPartnerOrganisations())
					.map((organisation) => ({
						...organisation,
						label: organisation.domain ? `${organisation.name} (${organisation.domain})` : organisation.name,
					}))
			} catch {
				this.partners = []
			}
		},

		/**
		 * Read the case for the eyebrow; a failed read only leaves it out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		async loadCase() {
			if (this.targetCaseId === '') {
				return
			}
			try {
				const { data } = await axios.get(generateUrl(`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.targetCaseId)}`))
				this.kase = data || null
			} catch {
				this.kase = null
			}
		},

		/**
		 * Show or hide one case field for the partner.
		 *
		 * @param {string} field The case field.
		 * @param {boolean} on Whether the partner sees it.
		 * @return {void}
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		toggleField(field, on) {
			this.sharedFields = on
				? [...new Set([...this.sharedFields, field])]
				: this.sharedFields.filter((entry) => entry !== field)
		},

		/**
		 * The request body for the chosen act.
		 *
		 * @return {{url: string, body: object}} Where to send it and what.
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		request() {
			if (this.mode === 'handover') {
				return {
					url: generateUrl('/apps/dossiq/api/transfers'),
					body: {
						caseId: this.targetCaseId,
						targetOrganization: this.organisation.id,
						reason: this.message.trim(),
						requestedDate: new Date().toISOString().slice(0, 10),
					},
				}
			}
			return {
				url: generateUrl('/apps/dossiq/api/shares'),
				body: {
					caseId: this.targetCaseId,
					shareType: 'partner',
					partnerId: this.organisation.id,
					permissionLevel: this.permission?.value || 'bekijken',
					message: this.message.trim(),
					fieldExclusions: this.limitFields
						? FEDERATION_ALLOWED_FIELDS.filter((field) => !this.sharedFields.includes(field))
						: [],
				},
			}
		},

		/**
		 * Share or hand over, and say so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-case-collaboration/spec.md
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			const { url, body } = this.request()
			try {
				const { data } = await axios.post(url, body)
				showSuccess(this.mode === 'share'
					? t('dossiq', 'The case is shared with {organisation}.', { organisation: this.organisation.name })
					: t('dossiq', 'The handover to {organisation} is requested.', { organisation: this.organisation.name }))
				emit('dossiq:case-shares:changed', { caseId: this.targetCaseId })
				this.$emit('saved', data)
				this.$emit('close')
			} catch (error) {
				this.error = error?.response?.data?.error || t('dossiq', 'The case could not be shared.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-share-org {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* The choice cards end at their border. 4px under them puts the first field
   16px down, as DqZaakSamenwerken draws it. */
.case-share-org > .cn-choice-cards {
	margin-bottom: 4px;
}

/* NcSelect's wrapper holds its 40px control 2px in, so 2px under the wrapper
   puts the control 16px above the next label, as drawn. */
.case-share-org > .v-select.select {
	margin-bottom: 2px;
}

/* The board puts the control 6px under the label; the text field's wrapper
   holds the control 2px in (its focus inset), so the gap is 4px. */
.case-share-org__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

/* 14px/600 on the normal line, as the board dialog's select labels. */
.case-share-org__label {
	font-size: 14px;
	font-weight: 600;
	line-height: normal;
}

.case-share-org__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.case-share-org__fields {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
}
</style>
