<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Is this household already known to another domain.

  🔴 THE GROUND IS CHOSEN BEFORE THE ANSWER, NOT FILLED IN AFTER IT. A ground
  recorded afterwards is a formality somebody completes; choosing it first is
  what makes the lookup a deliberate act, and it is what makes the log mean
  something when the person asks what was looked up about them. So the button
  does nothing until a ground is picked, and the server refuses without one
  anyway, because a required field in a dialog is not a guard.

  🔴 THE ANSWER IS THREE FACTS AND THERE IS NOTHING ELSE TO SHOW. That an open
  case exists, in which domain, and who to call. Enough to pick up the phone
  and not enough to learn anything about the household. Purpose limitation
  between Wmo, Jeugdwet and Participatiewet does not allow the 360 view, so
  this dialog has no "show more" and never will.

  THE GROUNDS COME FROM THE SERVER. A list written here as well would be a
  second copy that drifts, and a ground on screen the service does not
  recognise is a choice a consulent makes and is then refused for.

  @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Known in another domain')"
		size="normal"
		data-testid="cross-domain-lookup-dialog"
		@closing="$emit('close')">
		<div class="cross-domain">
			<p class="cross-domain__explainer">
				{{
					t(
						'dossiq',
						'This answers whether an open case exists in another domain, which domain it is and who to call. It shows nothing about the case itself.',
					)
				}}
			</p>

			<NcTextField
				v-model="bsn"
				data-testid="cross-domain-bsn"
				:label="t('dossiq', 'Citizen service number')" />

			<NcSelect
				v-model="ground"
				data-testid="cross-domain-ground"
				:inputLabel="t('dossiq', 'Ground for this lookup')"
				:options="grounds"
				:reduce="(option) => option.id"
				label="label" />

			<p class="cross-domain__notice">
				{{
					t(
						'dossiq',
						'The ground, the person, your name and the moment are recorded. The person can ask to see them.',
					)
				}}
			</p>

			<div
				v-if="answered"
				class="cross-domain__answer"
				data-testid="cross-domain-answer">
				<p v-if="found.length === 0" data-testid="cross-domain-none">
					{{ t('dossiq', 'No open case in another domain.') }}
				</p>
				<p
					v-for="entry in found"
					:key="entry.domain"
					data-testid="cross-domain-hit">
					{{
						t(
							'dossiq',
							'An open case exists in {domain}. Contact: {contact}.',
							{
								domain: entry.domain,
								contact: entry.contact,
							},
						)
					}}
				</p>
			</div>

			<p
				v-if="error"
				class="cross-domain__error"
				data-testid="cross-domain-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="cross-domain-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
			<NcButton
				data-testid="cross-domain-submit"
				variant="primary"
				:disabled="!canLookUp"
				@click="lookUp">
				{{ t('dossiq', 'Look up') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'CrossDomainLookupDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * The domain the caller works in, which is left out of the answer:
		 * they can already see their own work.
		 */
		domain: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			bsn: '',
			ground: '',
			grounds: [],
			found: [],
			answered: false,
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * Whether the lookup may run.
		 *
		 * Both halves, and the ground is not defaulted: a pre-picked ground is
		 * a ground nobody chose, which is the formality this design refuses.
		 *
		 * @return {boolean} True when it may.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		canLookUp() {
			return (
				this.busy === false
				&& this.bsn.trim().length > 0
				&& Boolean(this.ground)
			)
		},
	},

	async mounted() {
		await this.loadGrounds()
	},

	methods: {
		t,

		/**
		 * The grounds the server recognises.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		async loadGrounds() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/dossiq/api/cross-domain/grounds'),
				)
				this.grounds = Array.isArray(data?.grounds) ? data.grounds : []
			} catch {
				// NOT an empty list quietly. A picker with no grounds in it and
				// a picker whose grounds could not be read look identical, and
				// the second is an outage a consulent should be told about
				// rather than left staring at an empty dropdown.
				this.error = t(
					'dossiq',
					'The grounds could not be read, so no lookup can be made.',
				)
			}
		},

		/**
		 * Ask, on the chosen ground.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
		 */
		async lookUp() {
			if (!this.canLookUp) {
				return
			}
			this.busy = true
			this.error = ''
			this.answered = false
			try {
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/cross-domain/lookup'),
					{
						bsn: this.bsn.trim(),
						domain: this.domain,
						ground: this.ground,
					},
				)
				this.found = Array.isArray(data?.found) ? data.found : []
				this.answered = true
			} catch (e) {
				// The server's own sentence, which names the rule it refused
				// on. Replacing it here is how "choose the ground you are
				// looking this person up on" becomes "request failed".
				this.error =
					e?.response?.data?.message
					|| t('dossiq', 'The lookup was not made.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cross-domain {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.cross-domain__explainer,
.cross-domain__notice {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.cross-domain__answer {
	border-inline-start: 4px solid var(--color-border);
	padding-inline-start: 12px;
}

.cross-domain__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
