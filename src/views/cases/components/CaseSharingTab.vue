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
		<NcNoteCard
			v-if="linksForbidden"
			type="info"
			data-testid="case-sharing-links-forbidden">
			{{
				t(
					'dossiq',
					'Only the people this case is assigned to can see and manage its access links.',
				)
			}}
		</NcNoteCard>

		<p
			v-if="deelzaken.length > 0"
			class="case-sharing-tab__inheritance"
			data-testid="sharing-reaches-deelzaken">
			{{ inheritanceWarning }}
		</p>

		<ShareTab
			:shares="shares"
			:loading="loading"
			:links="links"
			:linksLoading="linksLoading"
			:federatedShares="federatedShares"
			:federatedLoading="federatedLoading"
			@revoke="revokeShare"
			@createLink="createAccessLinkDialogOpen = true"
			@revokeLink="revokeLink"
			@pauseLink="pauseLink"
			@previewLink="previewLink"
			@createPartnerShare="createShareDialogOpen = true"
			@transferCase="transferDialogOpen = true"
			@createFederatedShare="createFederatedShareDialogOpen = true"
			@revokeFederated="revokeFederatedShare"
			@openActivity="openActivity" />

		<CreateAccessLinkDialog
			:open="createAccessLinkDialogOpen"
			:caseId="objectId"
			:documents="caseDocuments"
			@update:open="createAccessLinkDialogOpen = $event"
			@created="createLink" />

		<!--
			What the holder reads, so a handler can check a link before they
			send it. The body comes from OpenRegister's own reader and dossiq
			strips it a second time, so an internal that OpenRegister starts
			publishing tomorrow does not reach this pane today.
		-->
		<div v-if="preview" class="case-sharing-tab__preview">
			<h4>
				{{
					t('dossiq', 'What {who} sees', {
						who: previewOf || t('dossiq', 'the holder'),
					})
				}}
			</h4>
			<pre>{{ preview }}</pre>
			<NcButton @click="preview = null">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</div>

		<CreateShareDialog
			:open="createShareDialogOpen"
			:caseId="objectId"
			:partners="partners"
			@update:open="createShareDialogOpen = $event"
			@created="createShare" />

		<CaseTransferDialog
			:open="transferDialogOpen"
			:caseId="objectId"
			:partners="partners"
			@update:open="transferDialogOpen = $event"
			@submitted="initiateTransfer" />

		<CreateFederatedShareDialog
			:open="createFederatedShareDialogOpen"
			:caseId="objectId"
			:documents="caseDocuments"
			@update:open="createFederatedShareDialogOpen = $event"
			@created="createFederatedShare" />

		<FederatedActivityPanel
			v-if="activeFederatedShareId"
			:open="activityPanelOpen"
			:federatedShareId="activeFederatedShareId"
			:entries="activityEntries"
			:loading="activityLoading"
			@update:open="activityPanelOpen = $event"
			@post="postActivity" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import CaseTransferDialog from '../../../dialogs/CaseTransferDialog.vue'
import CreateAccessLinkDialog from '../../../dialogs/CreateAccessLinkDialog.vue'
import CreateFederatedShareDialog from '../../../dialogs/CreateFederatedShareDialog.vue'
import CreateShareDialog from '../../../dialogs/CreateShareDialog.vue'
import FederatedActivityPanel from '../../../dialogs/FederatedActivityPanel.vue'
import ShareTab from './ShareTab.vue'
import { useObjectStore } from '../../../store/modules/object.js'
import {
	createFederatedShareEndpoint,
	federatedActivityEndpoint,
	federatedSharesListEndpoint,
	revokeFederatedShareEndpoint,
} from '../../../utils/federatedShareHelpers.js'

export default {
	name: 'CaseSharingTab',
	components: {
		ShareTab,
		NcButton,
		NcNoteCard,
		CreateAccessLinkDialog,
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
			links: [],
			linksLoading: false,
			linksForbidden: false,
			createAccessLinkDialogOpen: false,
			preview: null,
			previewOf: '',
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
			deelzaken: [],
		}
	},

	computed: {
		/**
		 * What a share on this case will reach beyond this case (D-5).
		 *
		 * Said BEFORE the share is made, not after. The failure this row is
		 * about, inverted: a handler who shares a parent expecting the
		 * children to stay private has already widened them by the time any
		 * later screen could tell them so.
		 *
		 * @return {string} The sentence.
		 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
		 */
		inheritanceWarning() {
			return n(
				'dossiq',
				'This case has one sub-case. Sharing this case lets the holder read it too.',
				'This case has %n sub-cases. Sharing this case lets the holder read them too.',
				this.deelzaken.length,
			)
		},
	},

	/**
	 * Load the three surfaces this tab owns: the access links, the partner
	 * shares and the federated shares, plus what the dialogs need to offer.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	mounted() {
		this.loadShares()
		this.loadLinks()
		this.loadFederatedShares()
		this.loadPartners()
		this.loadCaseDocuments()
		this.loadDeelzaken()
	},

	methods: {
		/**
		 * Read the sub-cases hanging under this case, so the warning above can
		 * say how many a share reaches.
		 *
		 * A failed read leaves the list empty and says nothing. That is the
		 * right way round here and only here: the warning is a courtesy on top
		 * of a rule OpenRegister enforces, so a missing warning costs a
		 * handler a surprise, while a warning about sub-cases that do not
		 * exist would teach them to ignore the line.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
		 */
		async loadDeelzaken() {
			if (!this.objectId) {
				return
			}
			try {
				const rows = await useObjectStore().fetchCollection('case', {
					parentCase: this.objectId,
					_limit: 100,
				})
				this.deelzaken = rows || []
			} catch {
				this.deelzaken = []
			}
		},

		/**
		 * Load every access link on this case, each with its state.
		 *
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
		 */
		async loadLinks() {
			if (!this.objectId) {
				return
			}
			this.linksLoading = true
			this.linksForbidden = false
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/dossiq/api/access-links/case/${encodeURIComponent(this.objectId)}`,
					),
				)
				this.links = response.data?.results || []
			} catch (err) {
				this.links = []
				// A 403 is an ANSWER, not a failure: links are managed by the
				// people a case is assigned to. This tab loads on every case
				// detail, opened or not, so shouting about it put a red toast on
				// every case a handler looked at. Said in the tab instead.
				this.linksForbidden = err?.response?.status === 403
				if (!this.linksForbidden) {
					showError(t('dossiq', 'Could not load the links on this case'))
				}
			} finally {
				this.linksLoading = false
			}
		},

		/**
		 * Mint a link and show the handler the address to send.
		 *
		 * @param {object} payload the link payload from CreateAccessLinkDialog.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		async createLink(payload) {
			try {
				const response = await axios.post(
					generateUrl('/apps/dossiq/api/shares'),
					payload,
				)
				this.createAccessLinkDialogOpen = false
				showSuccess(
					t('dossiq', 'The link is ready: {url}', {
						url: response.data?.url || '',
					}),
				)
				this.loadLinks()
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not create the link'),
				)
			}
		},

		/**
		 * Revoke a link. OpenRegister allows this only for the colleague who
		 * created it, so the refusal is shown rather than hidden.
		 *
		 * @param {object} link the link row.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		async revokeLink(link) {
			try {
				await axios.delete(
					generateUrl(
						`/apps/dossiq/api/access-links/${encodeURIComponent(link.accessLinkId)}`,
					),
					{ params: { caseId: this.objectId } },
				)
				showSuccess(t('dossiq', 'The link no longer opens the case'))
				this.loadLinks()
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not revoke the link'),
				)
			}
		},

		/**
		 * Switch a link off, or back on.
		 *
		 * @param {object} link the link row.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		async pauseLink(link) {
			try {
				await axios.put(
					generateUrl(
						`/apps/dossiq/api/access-links/${encodeURIComponent(link.accessLinkId)}`,
					),
					{ caseId: this.objectId, disabled: link.state !== 'paused' },
				)
				this.loadLinks()
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not change the link'),
				)
			}
		},

		/**
		 * Show the handler what the holder of this link reads.
		 *
		 * @param {object} link the link row.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
		 */
		async previewLink(link) {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/dossiq/api/access-links/${encodeURIComponent(link.accessLinkId)}/preview`,
					),
					{ params: { caseId: this.objectId } },
				)
				this.preview = response.data?.preview || null
				this.previewOf = link.label || ''
			} catch (err) {
				this.preview = null
				showError(
					err.response?.data?.error
						|| t('dossiq', 'That link opens nothing any more'),
				)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async loadShares() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/dossiq/caseShare'),
					{
						params: { caseId: this.objectId, shareType: 'partner' },
					},
				)
				this.shares = response.data?.results || []
			} catch (err) {
				showError(t('dossiq', 'Could not load partner shares'))
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
				const response = await axios.get(
					generateUrl(federatedSharesListEndpoint()),
					{
						params: { caseId: this.objectId },
					},
				)
				this.federatedShares = response.data?.results || []
			} catch (err) {
				// Non-fatal: the federation leaf may not be installed on this
				// instance — the tab still shows partner shares.
				this.federatedShares = []
			} finally {
				this.federatedLoading = false
			}
		},

		/**
		 * Load the ketenpartners this case may be shared with.
		 *
		 * Reads OpenRegister's Organisation, not dossiq's retired
		 * `partnerOrganization` schema. A ketenpartner IS an organisation, and
		 * every instance used to hold two answers to "which organisations does
		 * this system know about". `occ dossiq:migrate-partners` moved the rows
		 * PRESERVING each partner's uuid, so the `partnerId` already stored on
		 * existing caseShare objects keeps resolving.
		 *
		 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
		 * @spec openspec/changes/partners-are-organisations/specs/partner-organisations/spec.md
		 *
		 * @return {Promise<void>}
		 */
		async loadPartners() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/organisations'),
				)
				// A partner is somebody ELSE's organisation that this instance
				// shares cases WITH. Without the type filter the picker would
				// offer this municipality its own tenant to share a case with.
				this.partners = (response.data?.results || []).filter(
					(organisation) => organisation.type === 'partner',
				)
			} catch (err) {
				this.partners = []
				showError(t('dossiq', 'Could not load partner organisations'))
			}
		},

		/** @spec openspec/specs/case-share-via-shares-leaf/spec.md */
		async loadCaseDocuments() {
			if (!this.objectId) {
				return
			}
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.objectId)}`,
					),
				)
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
				await axios.post(generateUrl('/apps/dossiq/api/shares'), payload)
				showSuccess(t('dossiq', 'Share created'))
				this.createShareDialogOpen = false
				this.loadShares()
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not create share'),
				)
			}
		},

		/**
		 * @param {string} shareId the caseShare UUID to revoke.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		async revokeShare(shareId) {
			try {
				await axios.delete(
					generateUrl(
						`/apps/dossiq/api/shares/${encodeURIComponent(shareId)}`,
					),
				)
				showSuccess(t('dossiq', 'Share revoked'))
				this.loadShares()
			} catch (err) {
				showError(t('dossiq', 'Could not revoke share'))
			}
		},

		/**
		 * @param {object} payload the transfer-initiation payload (incl. optional remoteCloudId).
		 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
		 */
		async initiateTransfer(payload) {
			try {
				await axios.post(generateUrl('/apps/dossiq/api/transfers'), payload)
				showSuccess(t('dossiq', 'Transfer request submitted'))
				this.transferDialogOpen = false
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not submit transfer request'),
				)
			}
		},

		/**
		 * @param {object} payload the federated-share creation payload (shapeFederatedSharePayload output).
		 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
		 */
		async createFederatedShare(payload) {
			try {
				await axios.post(
					generateUrl(createFederatedShareEndpoint()),
					payload,
				)
				showSuccess(t('dossiq', 'Case shared with remote organisation'))
				this.createFederatedShareDialogOpen = false
				this.loadFederatedShares()
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not create federated share'),
				)
			}
		},

		/**
		 * @param {string} shareId the caseFederatedShare UUID to revoke.
		 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
		 */
		async revokeFederatedShare(shareId) {
			try {
				await axios.delete(
					generateUrl(revokeFederatedShareEndpoint(shareId)),
				)
				showSuccess(t('dossiq', 'Federated share revoked'))
				this.loadFederatedShares()
			} catch (err) {
				showError(t('dossiq', 'Could not revoke federated share'))
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
				const response = await axios.get(
					generateUrl(federatedActivityEndpoint(shareId)),
				)
				this.activityEntries = response.data?.entries || []
			} catch (err) {
				showError(t('dossiq', 'Could not load activity'))
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
				await axios.post(
					generateUrl(federatedActivityEndpoint(payload.federatedShareId)),
					{
						message: payload.message,
					},
				)
				this.openActivity(payload.federatedShareId)
			} catch (err) {
				showError(
					err.response?.data?.error
						|| t('dossiq', 'Could not post activity'),
				)
			}
		},
	},
}
</script>

<style scoped>
.case-sharing-tab {
	height: 100%;
}

.case-sharing-tab__preview {
	margin: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.case-sharing-tab__preview pre {
	max-height: 320px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-size: 12px;
}
</style>
