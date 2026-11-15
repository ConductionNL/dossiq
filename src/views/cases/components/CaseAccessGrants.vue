<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseAccessGrants — who on this instance was given access to this one case,
  by name or by group, and the form that grants or withdraws it.

  The grants are OpenRegister's object-level shares
  (object-level-sharing-and-private-scope): a real Nextcloud share on the
  case's folder, listed, made and withdrawn through OpenRegister's sharing
  endpoints. OpenRegister decides who may manage them (the case's owner and
  administrators); anybody else is told so instead of shown an empty list.

  @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
-->
<template>
	<section class="case-access-grants" data-testid="case-access-grants">
		<h4>{{ t('dossiq', 'People and groups with access') }}</h4>

		<NcNoteCard v-if="forbidden" type="info" data-testid="case-access-grants-forbidden">
			{{ t('dossiq', 'Only the owner of this case and administrators can see and change who has access to it.') }}
		</NcNoteCard>

		<template v-else>
			<p v-if="loading" class="case-access-grants__note">
				{{ t('dossiq', 'Loading…') }}
			</p>
			<p v-else-if="loadFailed" class="case-access-grants__note" role="alert">
				{{ t('dossiq', 'The access to this case could not be loaded.') }}
			</p>
			<p v-else-if="grants.length === 0" class="case-access-grants__note">
				{{ t('dossiq', 'Nobody was given access to this case by name or group.') }}
			</p>
			<ul v-else class="case-access-grants__list">
				<li
					v-for="grant in grants"
					:key="grant.id"
					class="case-access-grants__item"
					:data-grant="grant.id">
					<span class="case-access-grants__who">{{ grant.sharedWith }}</span>
					<span class="case-access-grants__meta">
						{{ grantLabel(grant) }}
					</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:data-testid="`case-access-grant-withdraw-${grant.id}`"
						@click="withdraw(grant)">
						{{ t('dossiq', 'Withdraw') }}
					</NcButton>
				</li>
			</ul>

			<form class="case-access-grants__form" data-testid="case-access-grant-form" @submit.prevent="giveAccess">
				<NcSelect
					v-model="draftType"
					:options="typeOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false"
					:inputLabel="t('dossiq', 'Give access to')" />
				<NcTextField
					v-model="draftName"
					:label="draftType === 'group' ? t('dossiq', 'Group') : t('dossiq', 'User name')"
					data-testid="case-access-grant-name" />
				<NcCheckboxRadioSwitch v-model="draftCanEdit" type="switch">
					{{ t('dossiq', 'Can also edit the case') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="busy || draftName.trim() === ''"
					data-testid="case-access-grant-submit">
					{{ t('dossiq', 'Give access') }}
				</NcButton>
			</form>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { caseGrantsEndpoint, grantPermissions, personalGrants } from '../../../utils/caseAccessGrants.js'

export default {
	name: 'CaseAccessGrants',
	components: { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** Case UUID. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			grants: [],
			loading: false,
			loadFailed: false,
			forbidden: false,
			busy: false,
			draftType: 'user',
			draftName: '',
			draftCanEdit: false,
		}
	},

	computed: {
		typeOptions() {
			return [
				{ id: 'user', label: t('dossiq', 'A person') },
				{ id: 'group', label: t('dossiq', 'A group') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the case's grants to users and groups.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.loadFailed = false
			try {
				const { data } = await axios.get(generateUrl(caseGrantsEndpoint(this.objectId)))
				this.grants = personalGrants(data?.results)
				this.forbidden = false
			} catch (error) {
				this.grants = []
				if (error?.response?.status === 403) {
					this.forbidden = true
				} else {
					this.loadFailed = true
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Grant the typed user or group access to this case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
		 */
		async giveAccess() {
			const shareWith = this.draftName.trim()
			if (shareWith === '') {
				return
			}
			this.busy = true
			try {
				await axios.post(generateUrl(caseGrantsEndpoint(this.objectId)), {
					type: this.draftType,
					shareWith,
					permissions: grantPermissions(this.draftCanEdit),
				})
				showSuccess(t('dossiq', 'Access given'))
				this.draftName = ''
				this.draftCanEdit = false
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.message || t('dossiq', 'The access could not be given.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Withdraw one grant.
		 *
		 * @param {object} grant The grant.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
		 */
		async withdraw(grant) {
			this.busy = true
			try {
				await axios.delete(generateUrl(caseGrantsEndpoint(this.objectId, grant.id)))
				showSuccess(t('dossiq', 'Access withdrawn'))
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.message || t('dossiq', 'The access could not be withdrawn.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * What a grant says beside its name: person or group, and read or edit.
		 *
		 * @param {object} grant The grant.
		 * @return {string}
		 */
		grantLabel(grant) {
			const who = grant.type === 'group' ? t('dossiq', 'Group') : t('dossiq', 'Person')
			const what = grant.canEdit ? t('dossiq', 'can edit') : t('dossiq', 'can read')
			return `${who} · ${what}`
		},
	},
}
</script>

<style scoped>
.case-access-grants {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.case-access-grants__note {
	color: var(--color-text-maxcontrast);
}

.case-access-grants__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0;
	list-style: none;
}

.case-access-grants__item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.case-access-grants__who {
	font-weight: bold;
}

.case-access-grants__meta {
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.case-access-grants__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
