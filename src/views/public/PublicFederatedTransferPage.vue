<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  PublicFederatedTransferPage — the remote-org accept/reject surface for a
  federated zaakoverdracht (case transfer). Authenticated exclusively via
  the transfer-scoped OpenRegister federated-share bearer token in the URL
  (`shareToken`) — never a local Nextcloud session, since the caller is
  staff at another organisation who may not even have an account here.

  Mirrors PublicAppointmentPage/PublicStatusPage's token-in-URL pattern.
  No GET endpoint exists to pre-load transfer details (design.md keeps the
  public surface minimal: accept/reject only, never a broader read of the
  case) — the page presents the action, not a data view.

  @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-accepts-a-transfer-addressed-to-it-via-its-scoped-token
-->
<template>
	<div class="public-federated-transfer-page">
		<div v-if="result" class="public-federated-transfer-page__result">
			<NcNoteCard :type="result.success ? 'success' : 'error'">
				{{ result.message }}
			</NcNoteCard>
		</div>

		<div v-else class="public-federated-transfer-page__content">
			<h2>{{ t('dossiq', 'Case transfer request') }}</h2>
			<p class="public-federated-transfer-page__description">
				{{
					t(
						'dossiq',
						'Another organisation has requested to transfer custody of a case to your organisation. Review the request with your case handler before accepting.',
					)
				}}
			</p>

			<div class="form-group">
				<label for="public-federated-transfer-reason">{{
					t('dossiq', 'Reason (required to reject)')
				}}</label>
				<textarea
					id="public-federated-transfer-reason"
					v-model="reason"
					rows="3"
					:placeholder="
						t('dossiq', 'Explain why this transfer is being rejected...')
					" />
			</div>

			<div class="public-federated-transfer-page__actions">
				<NcButton
					type="error"
					:disabled="submitting"
					@click="respond('reject')">
					{{ t('dossiq', 'Reject') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="submitting"
					@click="respond('accept')">
					{{ t('dossiq', 'Accept') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { publicFederatedTransferEndpoint } from '../../utils/federatedShareHelpers.js'

export default {
	name: 'PublicFederatedTransferPage',
	components: { NcButton, NcNoteCard },
	props: {
		shareToken: { type: String, required: true },
		transferId: { type: String, required: true },
	},

	data() {
		return {
			reason: '',
			submitting: false,
			result: null,
		}
	},

	methods: {
		/**
		 * @param {string} action 'accept' or 'reject'.
		 *
		 * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-accepts-a-transfer-addressed-to-it-via-its-scoped-token
		 */
		async respond(action) {
			this.submitting = true
			try {
				const url = generateUrl(
					publicFederatedTransferEndpoint(
						this.shareToken,
						this.transferId,
					),
				)
				const response = await axios.put(url, {
					action,
					reason: this.reason,
				})
				if (response.data?.success) {
					this.result = {
						success: true,
						message:
							action === 'accept'
								? t(
										'dossiq',
										'You have accepted this case transfer.',
									)
								: t(
										'dossiq',
										'You have rejected this case transfer.',
									),
					}
				} else {
					this.result = {
						success: false,
						message:
							response.data?.error
							|| t('dossiq', 'Could not process this transfer.'),
					}
				}
			} catch (e) {
				this.result = {
					success: false,
					message:
						e.response?.data?.error
						|| t(
							'dossiq',
							'This transfer link is invalid, expired or already resolved.',
						),
				}
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.public-federated-transfer-page {
	max-width: 600px;
	margin: 0 auto;
	padding: 32px 24px;
	font-family: var(--font-face), sans-serif;
}

.public-federated-transfer-page__description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.public-federated-transfer-page .form-group {
	margin-bottom: 16px;
}

.public-federated-transfer-page .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.public-federated-transfer-page textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
}

.public-federated-transfer-page__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
