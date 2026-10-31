<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Change who may see one document (board DqDocumentenBeheer, the Who can see
	this card's Change exception). Opened by the card's open-modal action;
	CnFilesBrowser merges the selected file's `fileId` and `fileName` onto the
	props, and the case's dossier listing names the document behind that file,
	with the exception it carries now. The people and groups are picked from
	Nextcloud's sharee search; a reason is required. The server decides who may
	save it (whoever may change every case of the document) and restricts the
	file in OpenRegister in the same act.

	Spec: openspec/changes/document-access-exception/specs/document-access-exception/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Who can see this: {name}', { name: fileName })"
		data-testid="document-access-exception-dialog"
		:noClose="busy"
		@closing="onClose">
		<div class="document-access-exception-dialog">
			<p class="document-access-exception-dialog__intro">
				{{ t('dossiq', 'Only the people and groups you name see this document, besides the case\'s handler and administrators.') }}
			</p>

			<NcSelect
				v-model="principals"
				data-testid="document-access-exception-principals"
				:inputLabel="t('dossiq', 'People and groups')"
				:options="options"
				:multiple="true"
				:loading="searching"
				:filterBy="() => true"
				label="label"
				:disabled="busy || loading"
				@search="onSearch" />

			<NcTextField
				v-model="reason"
				data-testid="document-access-exception-reason"
				:label="t('dossiq', 'Reason')"
				:disabled="busy || loading" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="onClose">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="hadException"
				data-testid="document-access-exception-remove"
				:disabled="busy || loading"
				@click="onRemove">
				{{ t('dossiq', 'Remove exception') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="document-access-exception-save"
				:disabled="!canSave"
				@click="onSave">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

/** Nextcloud's share types for a person and a group. */
const SHARE_TYPE_USER = 0
const SHARE_TYPE_GROUP = 1

export default {
	name: 'DocumentAccessExceptionDialog',
	components: { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** The selected file's Nextcloud id, merged on by CnFilesBrowser. */
		fileId: {
			type: [String, Number],
			default: '',
		},

		/** The selected file's name, for the title. */
		fileName: {
			type: String,
			default: '',
		},

		/** May arrive as the unresolved `@objectId` token; the route answers then. */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			documentId: '',
			principals: [],
			reason: '',
			hadException: false,
			found: [],
			searching: false,
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The case, from the prop unless it still holds an `@` token.
		 *
		 * @return {string} The case id, or ''.
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		/**
		 * What the picker offers: what is picked, then what the search found.
		 *
		 * @return {Array<{id: string, label: string, type: string, value: string}>} The options.
		 */
		options() {
			const picked = new Set(this.principals.map((option) => option.id))
			return [...this.principals, ...this.found.filter((option) => !picked.has(option.id))]
		},

		/**
		 * Saving needs the document, someone named and a reason.
		 *
		 * @return {boolean} True when Save may be pressed.
		 */
		canSave() {
			return !this.busy && !this.loading && this.documentId !== ''
				&& this.principals.length > 0 && this.reason.trim() !== ''
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Find the document behind the file, and the exception it carries now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(
					'/apps/dossiq/api/cases/{caseId}/dossier',
					{ caseId: this.resolvedCaseId },
				))
				const rows = Array.isArray(data?.informatieobjecten) ? data.informatieobjecten : []
				const row = rows.find((candidate) => Number(candidate.fileId) === Number(this.fileId))
				if (!row) {
					this.error = t('dossiq', 'This file has no document record yet, so who sees it cannot be changed.')
					return
				}
				this.documentId = String(row.id || '')
				const exception = row.accessException || null
				const users = Array.isArray(exception?.users) ? exception.users : []
				const groups = Array.isArray(exception?.groups) ? exception.groups : []
				const names = Array.isArray(row.accessExceptionNames) ? row.accessExceptionNames : []
				this.principals = [
					...users.map((uid, index) => this.option(SHARE_TYPE_USER, uid, names[index] || uid)),
					...groups.map((gid, index) => this.option(SHARE_TYPE_GROUP, gid, names[users.length + index] || gid)),
				]
				this.reason = String(exception?.reason || '')
				this.hadException = this.principals.length > 0
			} catch {
				this.error = t('dossiq', 'The document could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * One picker option.
		 *
		 * @param {number} shareType 0 for a person, 1 for a group.
		 * @param {string} value The user or group id.
		 * @param {string} label The display name.
		 * @return {{id: string, label: string, type: string, value: string}} The option.
		 */
		option(shareType, value, label) {
			const type = shareType === SHARE_TYPE_GROUP ? 'group' : 'user'
			return { id: `${type}:${value}`, label, type, value }
		},

		/**
		 * Search people and groups through Nextcloud's sharee search.
		 *
		 * @param {string} term What was typed.
		 * @return {Promise<void>}
		 */
		async onSearch(term) {
			const search = String(term || '').trim()
			if (search.length < 2) {
				this.found = []
				return
			}
			this.searching = true
			try {
				const { data } = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
					params: { search, itemType: 'file', perPage: 20, shareType: [SHARE_TYPE_USER, SHARE_TYPE_GROUP], format: 'json' },
				})
				const result = data?.ocs?.data || {}
				const entries = [
					...(result.exact?.users || []), ...(result.users || []),
					...(result.exact?.groups || []), ...(result.groups || []),
				]
				this.found = entries
					.filter((entry) => entry?.value && [SHARE_TYPE_USER, SHARE_TYPE_GROUP].includes(entry.value.shareType))
					.map((entry) => this.option(entry.value.shareType, String(entry.value.shareWith), String(entry.label || entry.value.shareWith)))
			} catch {
				this.found = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Save the exception.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
		 */
		async onSave() {
			await this.send('put', {
				users: this.principals.filter((option) => option.type === 'user').map((option) => option.value),
				groups: this.principals.filter((option) => option.type === 'group').map((option) => option.value),
				reason: this.reason.trim(),
			}, t('dossiq', 'Who can see this document was changed'))
		},

		/**
		 * Remove the exception.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
		 */
		async onRemove() {
			await this.send('delete', { reason: this.reason.trim() }, t('dossiq', 'Everyone on the case sees this document again'))
		},

		/**
		 * Send one change and report it; the server's own sentence on a refusal.
		 *
		 * @param {string} method `put` or `delete`.
		 * @param {object} body The request body.
		 * @param {string} done What to say when it worked.
		 * @return {Promise<void>}
		 */
		async send(method, body, done) {
			this.busy = true
			this.error = ''
			try {
				const url = generateUrl('/apps/dossiq/api/informatieobjecten/{id}/access-exception', { id: this.documentId })
				if (method === 'delete') {
					await axios.delete(url, { data: body })
				} else {
					await axios.put(url, body)
				}
				showSuccess(done)
				emit('cn:page:refresh')
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.error || t('dossiq', 'Who can see this document could not be changed.')
			} finally {
				this.busy = false
			}
		},

		/** Close, unless a request is running. */
		onClose() {
			if (!this.busy) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.document-access-exception-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
}

.document-access-exception-dialog__intro {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
