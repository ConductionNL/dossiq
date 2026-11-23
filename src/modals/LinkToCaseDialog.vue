<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcModal size="normal" labelId="link-to-case-title" @close="$emit('close')">
		<!-- In the board look, so the theme's paragraph and heading sizes, which spare only .cn-look-board, leave the board's type alone. -->
		<!-- Escape closes the dialog also from the search field, which NcModal's hotkey ignores. -->
		<div class="link-to-case cn-look-board" data-testid="link-to-case-dialog" @keydown.esc.stop="$emit('close')">
			<p class="link-to-case__eyebrow">
				{{ t('dossiq', 'Inbox') }}
			</p>
			<h2 id="link-to-case-title" class="link-to-case__title">
				{{ t('dossiq', 'Link to case') }}
			</h2>
			<p class="link-to-case__lead">
				{{ t('dossiq', 'The message and its attachments go on the case. It leaves the inbox.') }}
			</p>

			<div class="link-to-case__message" data-testid="link-to-case-message">
				<span class="link-to-case__avatar" aria-hidden="true">{{ initials }}</span>
				<div class="link-to-case__message-text">
					<strong class="link-to-case__subject">{{ message.subject }}</strong>
					<span class="link-to-case__meta">{{ messageMeta }}</span>
					<p v-if="message.preview" class="link-to-case__preview">
						{{ message.preview }}
					</p>
					<span v-if="message.attachments.length > 0" class="link-to-case__attachments" data-testid="link-to-case-attachments">
						<Paperclip :size="14" />
						{{ n('dossiq', '%n attachment goes with it: {names}', '%n attachments go with it: {names}', message.attachments.length, { names: message.attachments.join(', ') }) }}
					</span>
				</div>
			</div>

			<template v-if="suggested">
				<p class="link-to-case__label">
					{{ t('dossiq', 'Suggestion') }}
					<span class="link-to-case__label-hint">{{ t('dossiq', 'the case number is in the subject') }}</span>
				</p>
				<label class="link-to-case__case" :class="{ 'link-to-case__case--chosen': chosen && chosen.id === suggested.id }" data-testid="link-to-case-proposal">
					<input
v-model="chosenId"
						type="radio"
						name="link-to-case-case"
						:value="suggested.id">
					<span class="link-to-case__case-text">
						<span class="link-to-case__case-head">
							<span class="link-to-case__case-number">{{ suggested.identifier }}</span>
							<strong>{{ suggested.title }}</strong>
						</span>
						<span class="link-to-case__case-meta">
							<span v-if="suggested.status" class="link-to-case__pill">{{ suggested.status }}</span>
							{{ caseMeta(suggested) }}
						</span>
					</span>
				</label>
			</template>

			<label class="link-to-case__label" for="link-to-case-search">
				{{ suggested ? t('dossiq', 'Or search another case') : t('dossiq', 'Search a case') }}
			</label>
			<div class="link-to-case__search">
				<Magnify :size="20" class="link-to-case__search-icon" />
				<input
id="link-to-case-search"
					v-model="query"
					type="search"
					class="link-to-case__search-input"
					:placeholder="t('dossiq', 'Number or words from the title')"
					data-testid="link-to-case-search"
					@input="search">
			</div>
			<ul v-if="results.length > 0" class="link-to-case__results" data-testid="link-to-case-results">
				<li v-for="found in results" :key="found.id">
					<label class="link-to-case__case" :class="{ 'link-to-case__case--chosen': chosenId === found.id }">
						<input
v-model="chosenId"
							type="radio"
							name="link-to-case-case"
							:value="found.id"
							@change="pick(found)">
						<span class="link-to-case__case-text">
							<span class="link-to-case__case-head">
								<span class="link-to-case__case-number">{{ found.identifier }}</span>
								<strong>{{ found.title }}</strong>
							</span>
						</span>
					</label>
				</li>
			</ul>
			<p class="link-to-case__hint">
				{{ t('dossiq', 'You only see cases you may see. No case fits? Then choose New case in the inbox.') }}
			</p>

			<NcTextField
v-if="overridesProposal"
				v-model="reason"
				:label="t('dossiq', 'Why this case')"
				:placeholder="t('dossiq', 'Why does this message belong on that case?')"
				data-testid="link-to-case-reason" />

			<p class="link-to-case__label">
				{{ t('dossiq', 'When linking') }}
			</p>
			<NcCheckboxRadioSwitch v-if="senderLabel" v-model="addSender" data-testid="link-to-case-add-sender">
				{{ t('dossiq', 'Add {name} to the case as a party involved, if they are not on it yet', { name: senderLabel }) }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
v-if="handlerName"
				:modelValue="notifyHandler && !handlerIsReader"
				:disabled="handlerIsReader"
				data-testid="link-to-case-notify"
				@update:modelValue="notifyHandler = $event">
				{{ t('dossiq', 'Tell {name} a message was added', { name: handlerName }) }}
			</NcCheckboxRadioSwitch>
			<p v-if="handlerIsReader" class="link-to-case__hint" data-testid="link-to-case-notify-note">
				{{ t('dossiq', 'You handle this case yourself, so nobody else needs telling.') }}
			</p>

			<p v-if="error" class="link-to-case__error" role="alert">
				{{ error }}
			</p>

			<div class="link-to-case__footer">
				<NcButton variant="secondary" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
variant="primary"
					:disabled="!canLink"
					data-testid="link-to-case-confirm"
					@click="link">
					<template #icon>
						<LinkVariant :size="20" />
					</template>
					{{ chosen ? t('dossiq', 'Link to case {number}', { number: chosen.identifier }) : t('dossiq', 'Link to case') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcTextField } from '@nextcloud/vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'

/** The reason a link to the proposed case carries: the case number is in the subject. */
const PROPOSAL_REASON = 'The case number is in the subject'

/**
 * A case as the dialog shows it, from a stored case read with `_extend=status,caseType`.
 *
 * @param {object} row The stored case.
 * @return {object} `{ id, identifier, title, status, type, requester, assignee }`.
 */
export function caseRow(row) {
	const named = (value, field) => (value && typeof value === 'object' ? (value[field] || '') : '')
	return {
		id: row.id || row['@self']?.id || '',
		identifier: row.identifier || '',
		title: row.title || '',
		status: named(row.status, 'name'),
		type: named(row.caseType, 'title'),
		requester: row.initiatorDisplayName || '',
		assignee: typeof row.assignee === 'string' ? row.assignee : '',
	}
}

/**
 * Link a message from the inbox to a case (board DqKoppelAanZaak).
 *
 * Opened from an inbox row with the row as `object`: the message with its
 * text and attachments, the case its subject names as the chosen proposal, a
 * search for another case, and the two choices of "When linking". Linking
 * posts to the intake log's file-on-case route, which checks the intake role
 * and the caller's read right on the case. A link to the proposed case gives
 * the proposal as its reason; another case asks the reader why.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
export default {
	name: 'LinkToCaseDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcModal, NcTextField, LinkVariant, Magnify, Paperclip },

	props: {
		/** The inbox row: subject, sender, time, channel, preview, attachments and the suggestion. */
		object: { type: Object, default: () => ({}) },
		/** The intake log entry id. */
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close', 'done'],

	data() {
		return {
			suggested: null,
			results: [],
			chosen: null,
			chosenId: '',
			query: '',
			reason: '',
			addSender: true,
			notifyHandler: true,
			handlerName: '',
			error: '',
			sending: false,
			searchTimer: null,
		}
	},

	computed: {
		message() {
			const row = this.object || {}
			return {
				subject: row.subject || '',
				sender: row.sender || '',
				senderName: row.senderName || '',
				receivedAt: row.receivedAt || '',
				channel: row.channel || '',
				recipient: row.recipient || '',
				preview: row.preview || '',
				attachments: Array.isArray(row.attachments) ? row.attachments : [],
			}
		},

		senderLabel() {
			return this.message.senderName || this.message.sender
		},

		initials() {
			const words = this.senderLabel.replace(/@.*$/, '').split(/[\s._-]+/).filter(Boolean)
			return words.slice(0, 2).map((word) => word[0].toUpperCase()).join('')
		},

		messageMeta() {
			const parts = [this.message.sender]
			if (this.message.receivedAt) {
				parts.push(new Date(this.message.receivedAt).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }))
			}
			if (this.message.channel) {
				const mailbox = this.message.recipient.split('@')[0]
				parts.push(this.message.channel === 'email' ? t('dossiq', 'Email, {mailbox}@', { mailbox }) : this.message.channel)
			}
			return parts.filter(Boolean).join(' · ')
		},

		handlerIsReader() {
			const me = getCurrentUser()
			return Boolean(me && this.chosen && this.chosen.assignee === me.uid)
		},

		overridesProposal() {
			return Boolean(this.chosen) && (!this.suggested || this.chosen.id !== this.suggested.id)
		},

		canLink() {
			return Boolean(this.chosen) && !this.sending && (!this.overridesProposal || this.reason.trim() !== '')
		},
	},

	watch: {
		chosenId(id) {
			if (this.suggested && id === this.suggested.id) {
				this.chosen = this.suggested
			}
		},

		chosen(found) {
			this.loadHandler(found)
		},
	},

	created() {
		this.loadProposal()
	},

	methods: {
		t,
		n,

		/**
		 * Read the case the subject names, so the proposal shows its status, type, requester and handler.
		 */
		async loadProposal() {
			const id = this.object?.suggestion?.case
			if (!id) {
				return
			}
			try {
				const { data } = await axios.get(generateUrl(`/apps/openregister/api/objects/dossiq/case/${id}`), { params: { _extend: 'status,caseType' } })
				this.suggested = caseRow({ id, ...data })
			} catch {
				this.suggested = { id, identifier: this.object.suggestion.identifier || '', title: this.object.suggestion.title || '', status: '', type: '', requester: '', assignee: '' }
			}
			this.chosen = this.suggested
			this.chosenId = this.suggested.id
		},

		/**
		 * The handler's display name, for the proposal line and the notify choice.
		 *
		 * @param {object|null} found The chosen case.
		 */
		async loadHandler(found) {
			this.handlerName = ''
			if (!found || !found.assignee) {
				return
			}
			const me = getCurrentUser()
			if (me && me.uid === found.assignee) {
				// Autocomplete never answers the reader themselves.
				this.handlerName = me.displayName || me.uid
				return
			}
			try {
				const { data } = await axios.get(generateOcsUrl('core/autocomplete/get'), { params: { search: found.assignee, itemType: 'users', shareTypes: [0], limit: 10 } })
				const match = (data?.ocs?.data || []).find((user) => user.id === found.assignee)
				this.handlerName = match ? match.label : found.assignee
			} catch {
				this.handlerName = found.assignee
			}
		},

		/**
		 * The proposal's second line: type, requester and handler.
		 *
		 * @param {object} found The case.
		 * @return {string} The line.
		 */
		caseMeta(found) {
			const handler = this.chosen && this.chosen.id === found.id ? this.handlerName : ''
			return [found.type, found.requester, handler ? t('dossiq', 'handler {name}', { name: handler }) : ''].filter(Boolean).join(' · ')
		},

		/** Search the cases the reader may see, a moment after they stop typing. */
		search() {
			clearTimeout(this.searchTimer)
			const term = this.query.trim()
			if (term.length < 2) {
				this.results = []
				return
			}
			this.searchTimer = setTimeout(async () => {
				try {
					const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/dossiq/case'), { params: { _search: term, _limit: 8, _extend: 'status,caseType' } })
					this.results = (data?.results || []).map(caseRow).filter((found) => found.id && found.id !== this.suggested?.id)
				} catch {
					this.results = []
				}
			}, 250)
		},

		/**
		 * Choose a case from the search.
		 *
		 * @param {object} found The case.
		 */
		pick(found) {
			this.chosen = found
		},

		/** File the message on the chosen case, with the two choices. */
		async link() {
			if (!this.canLink) {
				return
			}
			this.sending = true
			this.error = ''
			try {
				await axios.post(generateUrl(`/apps/dossiq/api/mail-intake/log/${this.objectId}/file-on-case`), {
					caseId: this.chosen.id,
					reason: this.overridesProposal ? this.reason.trim() : PROPOSAL_REASON,
					addSender: this.addSender && Boolean(this.senderLabel),
					notifyHandler: this.notifyHandler && Boolean(this.handlerName) && !this.handlerIsReader,
				})
				showSuccess(t('dossiq', 'The message is on case {number}.', { number: this.chosen.identifier }))
				emit('cn:page:refresh', {})
				this.$emit('done', { caseId: this.chosen.id })
				this.$emit('close')
			} catch (e) {
				this.error = e?.response?.data?.message === 'reason_required'
					? t('dossiq', 'Say why this message belongs on that case.')
					: t('dossiq', 'The message could not be linked. Try again.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.link-to-case {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 24px;
}

.link-to-case__eyebrow {
	margin: 0;
	color: var(--color-primary-element);
	font-size: 13px;
	font-weight: 600;
}

.link-to-case__title {
	margin: 0;
	font-size: 20px;
	font-weight: 700;
}

.link-to-case__lead,
.link-to-case__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.link-to-case__hint {
	font-size: 13px;
}

.link-to-case__message {
	display: flex;
	gap: 12px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.link-to-case__avatar {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	inline-size: 32px;
	block-size: 32px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	font-weight: 700;
}

.link-to-case__message-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.link-to-case__meta,
.link-to-case__case-meta,
.link-to-case__attachments {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.link-to-case__preview {
	margin: 4px 0 0;
	font-size: 14px;
}

.link-to-case__attachments {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-top: 4px;
}

.link-to-case__label {
	margin: 8px 0 0;
	font-size: 14px;
	font-weight: 600;
}

.link-to-case__label-hint {
	margin-inline-start: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 400;
}

.link-to-case__case {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 10px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	font-weight: 400;
	cursor: pointer;
}

.link-to-case__case--chosen {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.link-to-case__case-head strong {
	font-weight: 600;
}

/* The theme draws a label's text bold; the board draws these lines regular. */
.link-to-case :deep(.checkbox-radio-switch__text) {
	font-weight: 400;
}

.link-to-case__case-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.link-to-case__case-head {
	display: flex;
	gap: 8px;
	align-items: baseline;
}

.link-to-case__case-number {
	color: var(--color-primary-element);
	font-weight: 600;
}

.link-to-case__pill {
	margin-inline-end: 6px;
	padding: 1px 8px;
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 12px;
	font-weight: 600;
}

.link-to-case__search {
	position: relative;
}

.link-to-case__search-icon {
	position: absolute;
	inset-block-start: 50%;
	inset-inline-start: 12px;
	transform: translateY(-50%);
	color: var(--color-text-maxcontrast);
}

.link-to-case__search-input {
	inline-size: 100%;
	block-size: 40px;
	margin: 0;
	padding-inline-start: 40px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
}

.link-to-case__results {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.link-to-case__error {
	margin: 0;
	color: var(--color-error-text);
}

.link-to-case__footer {
	display: flex;
	gap: 10px;
	justify-content: flex-end;
	margin-top: 12px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
