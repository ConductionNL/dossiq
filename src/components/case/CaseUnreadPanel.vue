<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	What is new on this case since the handler last looked, and where.

	A case that is unread tells a handler to look; a case whose Documents tab
	is unread tells them WHERE, and the second is the one that stops a document
	sitting unseen on a case for a week. So this strip sits directly above the
	tab bar and names each panel that holds something the reader has not seen,
	with how many.

	🔴 WHY THE COUNT IS ON A STRIP AND NOT ON THE TAB ITSELF. The tab bar is
	`CnTabsWidget` out of `@conduction/nextcloud-vue`: its tab entries render a
	label and an icon and nothing else, and it neither takes a badge nor emits
	its tab change, so a sibling widget cannot decorate a tab and cannot learn
	that one was opened. Both are one small library change (cluster 58/15), and
	the affordance belongs there so every list and detail page in the fleet
	gets it rather than dossiq alone. Until then the count is on the strip and
	the gesture that clears it is the strip's own button, which is the same
	write opening the tab will make.

	🔴 OPENING THE CASE MARKS THE CASE READ, NOT ITS TABS. The PUT this makes on
	mount records that the reader has seen the case and clears the
	notifications that were about it — one write, no separate dismissal, which
	is what makes the bell trustworthy. It deliberately does NOT stamp the
	sub-resources: OpenRegister only stamps the one named in `subResource`, so
	the Documents count survives opening the case and goes when the documents
	are actually looked at.

	THE COUNTS ARE READ BEFORE THE WRITE, in that order, on purpose. The GET
	answers what was unread at the moment the page opened; the PUT then changes
	it. Reading after writing would answer about a case the reader has just
	been recorded as having seen, which is a page that can never show anything.

	@spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
-->
<template>
	<div v-if="show" class="case-unread" data-testid="case-unread">
		<span class="case-unread__lead">{{ lead }}</span>

		<ul v-if="entries.length > 0" class="case-unread__list">
			<li v-for="entry in entries" :key="entry.name">
				<NcButton
					variant="tertiary"
					:disabled="busy"
					:data-testid="`case-unread-${entry.name}`"
					@click="acknowledge(entry.name)">
					{{ entry.label }}
				</NcButton>
			</li>
		</ul>

		<NcButton
			variant="tertiary"
			class="case-unread__reset"
			:disabled="busy"
			data-testid="case-unread-mark-unread"
			@click="putBackToUnread">
			{{ markUnreadLabel }}
		</NcButton>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { fetchReadState, markRead, markUnread } from '../../services/readStateApi.js'

/**
 * What each sub-resource OpenRegister counts is called on this page.
 *
 * `files` is always counted whether anything declares it or not, because
 * OpenRegister owns the object's folder. A name with no entry here falls back
 * to the name itself rather than being hidden: a count nobody can label is
 * still a count somebody should see.
 */
const PANEL_LABELS = {
	files: 'Files',
}

export default {
	name: 'CaseUnreadPanel',

	components: { NcButton },

	props: {
		/** The case this strip belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loaded: false,
			busy: false,
			counts: {},
			lastSeenAt: null,
			wasUnread: false,
		}
	},

	computed: {
		/**
		 * The case this strip is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * The panels holding something this reader has not seen.
		 *
		 * @return {Array<{name: string, label: string, count: number}>} The entries, largest first.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		entries() {
			return Object.entries(this.counts || {})
				.map(([name, count]) => ({ name, count: Number(count) || 0 }))
				.filter((entry) => entry.count > 0)
				.sort((a, b) => b.count - a.count)
				.map((entry) => ({
					...entry,
					label: t('dossiq', '{panel} ({count} new)', {
						panel: t('dossiq', PANEL_LABELS[entry.name] || entry.name),
						count: entry.count,
					}),
				}))
		},

		/**
		 * Whether the strip has anything to say at all.
		 *
		 * A case nobody has touched since the reader last looked says nothing,
		 * rather than saying "nothing new" on every case page forever.
		 *
		 * @return {boolean} True when there is something to report.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		show() {
			return this.loaded && (this.entries.length > 0 || this.wasUnread)
		},

		/**
		 * The sentence in front of the panels.
		 *
		 * @return {string} What changed, in the reader's language.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		lead() {
			if (this.entries.length > 0) {
				return t('dossiq', 'New since you last looked:')
			}

			return t('dossiq', 'You had not seen this case yet.')
		},

		/**
		 * The label of the put-it-back gesture.
		 *
		 * @return {string} The label in the reader's language.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		markUnreadLabel() {
			return t('dossiq', 'Mark unread')
		},
	},

	/**
	 * Read what is unread, then record that the case has been opened.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the state, then mark the case read and empty its notices.
		 *
		 * A failure here leaves the strip silent rather than showing an error
		 * band across the top of every case page: the read state is an aid to
		 * a handler, and an instance whose OpenRegister does not carry the
		 * change yet answers 404. Nothing else on the page depends on it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		async load() {
			if (this.caseId === '') {
				return
			}

			try {
				const state = await fetchReadState(this.caseId)
				this.counts = state.unreadCounts || {}
				this.lastSeenAt = state.lastSeenAt
				this.wasUnread = state.unread
				this.loaded = true
			} catch {
				// An instance whose OpenRegister does not carry the read state
				// yet answers 404, and a strip that is not drawn is the right
				// answer to that. Nothing else on the page depends on it.
				this.loaded = false
				return
			}

			try {
				await markRead(this.caseId)
			} catch {
				// The strip still shows what it read. A case that could not be
				// recorded as seen stays unread, which is the safe direction.
			}
		},

		/**
		 * Record that this panel has been read, and clear its notices.
		 *
		 * @param {string} name The sub-resource name.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		async acknowledge(name) {
			this.busy = true
			try {
				await markRead(this.caseId, name)
				this.counts = { ...this.counts, [name]: 0 }
			} catch (error) {
				const refusal = String(error?.response?.data?.message ?? '')
				showError(
					refusal !== ''
						? refusal
						: t('dossiq', 'This did not work. Try again.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Put the case back to unread for this reader, and say so in the list.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		async putBackToUnread() {
			this.busy = true
			try {
				await markUnread(this.caseId)
				this.wasUnread = true
				window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
			} catch (error) {
				const refusal = String(error?.response?.data?.message ?? '')
				showError(
					refusal !== ''
						? refusal
						: t('dossiq', 'This did not work. Try again.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-unread {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.case-unread__lead {
	font-weight: bold;
}

.case-unread__list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.case-unread__reset {
	margin-inline-start: auto;
}
</style>
