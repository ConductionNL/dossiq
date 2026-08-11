<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseSharingTab — sidebar container that wires the previously-orphaned
  ShareTab / CreateShareDialog / CaseTransferDialog components (verified
  zero references anywhere before this change — see design.md §7) plus the
  new federated-case-collaboration UI (CreateFederatedShareDialog,
  FederatedActivityPanel) into the real case-detail sidebar.

  This is the missing "glue" component: ShareTab/CreateShareDialog/
  CaseTransferDialog are pure presentational components (props + emits, no
  API calls of their own) — nothing previously mounted them or wired their
  events to the backend routes, so the partner-share/transfer feature was
  live in the backend but unreachable from any real UI. This tab owns the
  fetch/create/revoke API calls and passes data down.

  Registered in src/registry.js as `CaseSharingTab` and wired as a
  `component:` sidebar tab (alongside "audit"/"notes") on CaseDetail in
  src/manifest.json.

  @spec openspec/specs/federated-case-collaboration/spec.md#the-case-detail-sharing-surface-is-wired-not-orphaned
-->
<template>
	<div class="case-sharing-tab">
		<ShareTab
			:shares="shares"
			:loading="loading"
			:federated-shares="federatedShares"
			:federated-loading="federatedLoading"
			@revoke="revokeShare"
			@create-partner-share="createShareDialogOpen = true"
			@transfer-case="transferDialogOpen = true"
			@create-federated-share="createFederatedShareDialogOpen = true"
			@revoke-federated="revokeFederatedShare"
			@open-activity="openActivity" />

		<CreateShareDialog
			:open="createShareDialogOpen"
			:case-id="objectId"
			:partners="partners"
			@update:open="createShareDialogOpen = $event"
			@created="createShare" />

		<CaseTransferDialog
			:open="transferDialogOpen"
			:case-id="objectId"
			:partners="partners"
			@update:open="transferDialogOpen = $event"
			@submitted="initiateTransfer" />

		<CreateFederatedShareDialog
			:open="createFederatedShareDialogOpen"
			:case-id="objectId"
			:documents="caseDocuments"
			@update:open="createFederatedShareDialogOpen = $event"
			@created="createFederatedShare" />

		<FederatedActivityPanel
			v-if="activeFederatedShareId"
			:open="activityPanelOpen"
			:federated-share-id="activeFederatedShareId"
			:entries="activityEntries"
			:loading="activityLoading"
			@update:open="activityPanelOpen = $event"
			@post="postActivity" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import ShareTab from './ShareTab.vue'
import CreateShareDialog from '../../../dialogs/CreateShareDialog.vue'
import CaseTransferDialog from '../../../dialogs/CaseTransferDialog.vue'
import CreateFederatedShareDialog from '../../../dialogs/CreateFederatedShareDialog.vue'
import FederatedActivityPanel from '../../../dialogs/FederatedActivityPanel.vue'
import {
	federatedSharesListEndpoint,
	createFederatedShareEndpoint,
	revokeFederatedShareEndpoint,
	federatedActivityEndpoint,
} from '../../../utils/federatedShareHelpers.js'

export default {
	name: 'CaseSharingTab',
	components: {
		ShareTab,
		CreateShareDialog,
		CaseTransferDialog,
		CreateFederatedShareDialog,
		FederatedActivityPanel,
	},
	props: {
		/** Case UUID; forwarded by CnObjectSidebar's sharedTabProps. */
		objectId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			shares: [],
			loading: false,
			federatedShares: [],
			federatedLoading: false,
			partners: [],
			caseDocuments: [],
			createShareDialogOpen: false,
			transferDialogOpen: false,
			createFederatedShareDialogOpen: false,
			activityPanelOpen: false,
			activeFederatedShareId: null,
			activityEntries: [],
			activityLoading: false,
		}
	},
	mounted() {
		this.loadShares()
		this.loadFederatedShares()
		this.loadPartners()
		this.loadCaseDocuments()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async loadShares() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/procest/caseShare'), {
					params: { caseId: this.objectId, shareType: 'partner' },
				})
				this.shares = response.data?.results || []
			} catch (err) {
				showError(t('procest', 'Could not load partner shares'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/specs/federated-case-collaboration/spec.md#the-case-detail-sharing-surface-is-wired-not-orphaned
		 */
		async loadFederatedShares() {
			if (!this.objectId) {
				return
			}
			this.federatedLoading = true
			try {
				const response = await axios.get(generateUrl(federatedSharesListEndpoint()), {
					params: { caseId: this.objectId },
				})
				this.federatedShares = response.data?.results || []
			} catch (err) {
				// Non-fatal: the federation leaf may not be installed on this
				// instance — the tab still shows partner shares.
				this.federatedShares = []
			} finally {
				this.federatedLoading = false
			}
		},
		async loadPartners() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/procest/partnerOrganization'))
				this.partners = response.data?.results || []
			} catch (err) {
				this.partners = []
			}
		},
		async loadCaseDocuments() {
			if (!this.objectId) {
				return
			}
			try {
				const response = await axios.get(generateUrl(`/apps/openregister/api/objects/procest/case/${encodeURIComponent(this.objectId)}`))
				const docs = response.data?.documents || []
				this.caseDocuments = docs.map((id) => ({ id, name: id }))
			} catch (err) {
				this.caseDocuments = []
			}
		},
		/**
		 * @param {object} payload the partner-share creation payload.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		async createShare(payload) {
			try {
				await axios.post(generateUrl('/apps/procest/api/shares'), payload)
				showSuccess(t('procest', 'Share created'))
				this.createShareDialogOpen = false
				this.loadShares()
			} catch (err) {
				showError(err.response?.data?.error || t('procest', 'Could not create share'))
			}
		},
		/**
		 * @param {string} shareId the caseShare UUID to revoke.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		async revokeShare(shareId) {
			try {
				await axios.delete(generateUrl(`/apps/procest/api/shares/${encodeURIComponent(shareId)}`))
				showSuccess(t('procest', 'Share revoked'))
				this.loadShares()
			} catch (err) {
				showError(t('procest', 'Could not revoke share'))
			}
		},
		/**
		 * @param {object} payload the transfer-initiation payload (incl. optional remoteCloudId).
		 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
		 */
		async initiateTransfer(payload) {
			try {
				await axios.post(generateUrl('/apps/procest/api/transfers'), payload)
				showSuccess(t('procest', 'Transfer request submitted'))
				this.transferDialogOpen = false
			} catch (err) {
				showError(err.response?.data?.error || t('procest', 'Could not submit transfer request'))
			}
		},
		/**
		 * @param {object} payload the federated-share creation payload (shapeFederatedSharePayload output).
		 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
		 */
		async createFederatedShare(payload) {
			try {
				await axios.post(generateUrl(createFederatedShareEndpoint()), payload)
				showSuccess(t('procest', 'Case shared with remote organisation'))
				this.createFederatedShareDialogOpen = false
				this.loadFederatedShares()
			} catch (err) {
				showError(err.response?.data?.error || t('procest', 'Could not create federated share'))
			}
		},
		/**
		 * @param {string} shareId the caseFederatedShare UUID to revoke.
		 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
		 */
		async revokeFederatedShare(shareId) {
			try {
				await axios.delete(generateUrl(revokeFederatedShareEndpoint(shareId)))
				showSuccess(t('procest', 'Federated share revoked'))
				this.loadFederatedShares()
			} catch (err) {
				showError(t('procest', 'Could not revoke federated share'))
			}
		},
		/**
		 * @param {string} shareId the caseFederatedShare UUID to load activity for.
		 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
		 */
		async openActivity(shareId) {
			this.activeFederatedShareId = shareId
			this.activityPanelOpen = true
			this.activityLoading = true
			try {
				const response = await axios.get(generateUrl(federatedActivityEndpoint(shareId)))
				this.activityEntries = response.data?.entries || []
			} catch (err) {
				showError(t('procest', 'Could not load activity'))
			} finally {
				this.activityLoading = false
			}
		},
		/**
		 * @param {object} payload `{ federatedShareId, message }`.
		 * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
		 */
		async postActivity(payload) {
			try {
				await axios.post(generateUrl(federatedActivityEndpoint(payload.federatedShareId)), {
					message: payload.message,
				})
				this.openActivity(payload.federatedShareId)
			} catch (err) {
				showError(err.response?.data?.error || t('procest', 'Could not post activity'))
			}
		},
	},
}
</script>

<style scoped>
.case-sharing-tab {
	height: 100%;
}
</style>
