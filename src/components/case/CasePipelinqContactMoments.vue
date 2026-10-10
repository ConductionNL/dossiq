<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The case's contact moments as pipelinq's customer record holds them (board
	DqZaakContactmomenten), a section of the Communication tab.

	🔴 BY MEMBERSHIP, NOT BY ONE REFERENCE. pipelinq answers every moment whose
	case set holds this case, so one call filed on three cases shows on each of
	the three, with the line saying it is also elsewhere. A case the reader may
	not open is counted, never named.

	🔴 ABSENT IS NOT EMPTY. "Pipelinq is not installed" and "nothing in the
	customer record for this case" are different sentences and both are drawn.
	The list above this section, Communication, is dossiq's own record and stays
	the place a handler reads on an instance without pipelinq.

	Filing onto another case and taking a moment off this one are pipelinq's two
	acts, reached through dossiq's controller (FileContactMomentDialog for the
	first). Nothing here edits the case set.

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
-->
<template>
	<div class="case-pipelinq-moments" data-testid="case-pipelinq-moments">
		<p class="case-pipelinq-moments__lead">
			{{
				t(
					'dossiq',
					'From pipelinq: every contact moment on this case, also when it is on more cases. Newest first.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'The customer record could not be read')"
			:description="
				t('dossiq', 'Nothing was changed. Try again in a moment.')
			" />

		<NcEmptyContent
			v-else-if="!available"
			data-testid="case-pipelinq-moments-absent"
			:name="t('dossiq', 'Pipelinq is not installed on this instance')"
			:description="
				t(
					'dossiq',
					'There is no customer record here. The contact moments dossiq logs itself are in the Communication list.',
				)
			" />

		<NcEmptyContent
			v-else-if="moments.length === 0"
			data-testid="case-pipelinq-moments-empty"
			:name="
				t(
					'dossiq',
					'No contact moments in the customer record for this case yet',
				)
			" />

		<ul v-else class="case-pipelinq-moments__list">
			<li
				v-for="moment in moments"
				:key="moment.id"
				class="case-pipelinq-moments__row"
				data-testid="case-pipelinq-moment">
				<span class="case-pipelinq-moments__when">{{ whenOf(moment) }}</span>
				<span class="case-pipelinq-moments__body">
					<span class="case-pipelinq-moments__chips">
						<span
							v-if="moment.channel"
							class="case-pipelinq-moments__chip"
							>{{ moment.channel }}</span
						>
						<span
							v-if="moment.direction"
							class="case-pipelinq-moments__chip"
							>{{ directionOf(moment) }}</span
						>
						<span
							v-if="sharedLine(moment)"
							class="case-pipelinq-moments__chip case-pipelinq-moments__chip--shared"
							data-testid="case-pipelinq-moment-shared"
							>{{ sharedLine(moment) }}</span
						>
					</span>
					<span>{{ moment.summary || moment.subject }}</span>
					<span
						v-if="(moment.alsoOnCases || []).length > 0"
						class="case-pipelinq-moments__also"
						data-testid="case-pipelinq-moment-also">
						{{ t('dossiq', 'Also on:') }}
						<template
							v-for="(other, index) in moment.alsoOnCases"
							:key="other">
							<RouterLink
								:to="{ name: 'CaseDetail', params: { id: other } }"
								>{{
									caseLabels[other] || t('dossiq', 'case')
								}}</RouterLink
							><span v-if="index < moment.alsoOnCases.length - 1"
								>,
							</span>
						</template>
					</span>
				</span>
				<NcActions
					:aria-label="t('dossiq', 'Actions for this contact moment')">
					<NcActionButton
						data-testid="case-pipelinq-moment-file"
						@click="filing = moment">
						{{ t('dossiq', 'Also file on another case') }}
					</NcActionButton>
					<NcActionButton
						data-testid="case-pipelinq-moment-unfile"
						@click="unfile(moment)">
						{{ t('dossiq', 'Take off this case') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<p
			v-if="actionError"
			class="case-pipelinq-moments__error"
			role="alert"
			data-testid="case-pipelinq-moments-error">
			{{ actionError }}
		</p>

		<p
			v-if="available && moments.length > 0"
			class="case-pipelinq-moments__lead">
			{{
				t(
					'dossiq',
					'One conversation about three cases is one contact moment. Filing and taking off go through pipelinq, which records who did it and when.',
				)
			}}
		</p>

		<FileContactMomentDialog
			v-if="filing"
			:caseId="caseId"
			:moment="filing"
			@filed="load"
			@close="filing = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileContactMomentDialog from '../../dialogs/FileContactMomentDialog.vue'
import {
	fetchContactMoments,
	refusalOf,
	sharedLine,
	unfileContactMoment,
} from '../../services/pipelinqCaseApi.js'

export default {
	name: 'CasePipelinqContactMoments',

	components: {
		FileContactMomentDialog,
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			failed: false,
			available: false,
			moments: [],
			filing: null,
			actionError: '',
			caseLabels: {},
		}
	},

	computed: {
		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,
		sharedLine,

		/**
		 * Read the moments this case is a member of.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		async load() {
			if (this.caseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.failed = false
			try {
				const answer = await fetchContactMoments(this.caseId)
				this.available = answer?.available === true
				this.moments = Array.isArray(answer?.moments) ? answer.moments : []
				this.labelOtherCases()
			} catch {
				this.failed = true
				this.moments = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Name the other cases a moment is on, by number and title.
		 *
		 * Only the cases pipelinq named, which are the ones this reader may
		 * open; the rest are a count in the shared line. A case that does not
		 * answer keeps the plain word "case" as its link text.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		async labelOtherCases() {
			const wanted = [
				...new Set(this.moments.flatMap((m) => m.alsoOnCases || [])),
			]
				.filter((id) => !this.caseLabels[id])
				.slice(0, 10)

			await Promise.all(
				wanted.map(async (id) => {
					try {
						const { data } = await axios.get(
							generateUrl(
								'/apps/openregister/api/objects/dossiq/case/{id}',
								{ id },
							),
						)
						const label = [data?.identifier, data?.title]
							.filter(Boolean)
							.join(' ')
						if (label !== '') {
							this.caseLabels = { ...this.caseLabels, [id]: label }
						}
					} catch {
						// The link still opens the case; only its text stays plain.
					}
				}),
			)
		},

		/**
		 * Take one moment off this case, through pipelinq.
		 *
		 * @param {object} moment The moment.
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		async unfile(moment) {
			this.actionError = ''
			try {
				await unfileContactMoment(this.caseId, String(moment?.id || ''))
				await this.load()
			} catch (error) {
				this.actionError = refusalOf(
					error,
					t(
						'dossiq',
						'The contact moment could not be taken off this case.',
					),
				)
			}
		},

		/**
		 * The moment as a date and time a handler reads.
		 *
		 * @param {object} moment The moment.
		 * @return {string} The moment, '' when none.
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		whenOf(moment) {
			const at = new Date(String(moment?.occurredAt || ''))
			if (Number.isNaN(at.getTime())) {
				return ''
			}

			return at.toLocaleString(undefined, {
				day: 'numeric',
				month: 'short',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * The direction in the handler's words.
		 *
		 * @param {object} moment The moment.
		 * @return {string} Inbound, outbound, or the value pipelinq gave.
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
		 */
		directionOf(moment) {
			if (moment?.direction === 'inbound') {
				return t('dossiq', 'Inbound')
			}

			if (moment?.direction === 'outbound') {
				return t('dossiq', 'Outbound')
			}

			return String(moment?.direction || '')
		},
	},
}
</script>

<style scoped lang="scss">
.case-pipelinq-moments {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__lead {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__row {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 16px;
		align-items: flex-start;
		padding: 12px 0;
		border-top: 1px solid var(--color-border);
	}

	&__when {
		flex: 0 0 110px;
		color: var(--color-text-maxcontrast);
	}

	&__body {
		flex: 1 1 300px;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	&__chip {
		padding: 2px 10px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-dark);
		font-weight: 600;
	}

	&__chip--shared {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	&__also {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
