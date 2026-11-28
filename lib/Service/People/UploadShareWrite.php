<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCP\Constants;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use RuntimeException;
use Throwable;

/**
 * Creates the create-only e-mail share a file request is sent as.
 *
 * Split out of {@see FileRequestService}, which keeps checking who is asked,
 * whether anything refuses the send and where the upload lands; this class
 * builds the share from the checked request and has Nextcloud create (and
 * mail) it.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class UploadShareWrite {

	/**
	 * Constructor.
	 *
	 * @param IShareManager $shares Creates the share Nextcloud mails.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IShareManager $shares,
	) {
	}//end __construct()

	/**
	 * Create the upload share for a checked request and answer its token.
	 *
	 * @param UploadRequest $request The checked request.
	 *
	 * @return string The token of the created share.
	 *
	 * @throws RuntimeException 500 when the share manager refuses the share.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function send(UploadRequest $request): string {
		return (string)$this->createdShare(share: $this->uploadShare(request: $request))->getToken();
	}//end send()

	/**
	 * The create-only email share of the case folder.
	 *
	 * Create and nothing else: the party may put a file in, and can neither
	 * read nor change what is already in the case folder.
	 *
	 * @param UploadRequest $request The checked request.
	 *
	 * @return IShare
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function uploadShare(UploadRequest $request): IShare {
		$share = $this->shares->newShare();
		$share->setNode($request->folder);
		$share->setShareType(IShare::TYPE_EMAIL);
		$share->setSharedWith($request->email);
		$share->setSharedBy($request->user->getUID());
		$share->setShareOwner($request->folder->getOwner()?->getUID() ?? $request->user->getUID());
		$share->setPermissions(Constants::PERMISSION_CREATE);
		$share->setExpirationDate($request->expires);
		if ($request->note !== '') {
			$share->setNote($request->note);
		}

		return $share;
	}//end uploadShare()

	/**
	 * Create the share; a refusal of the share manager is reported.
	 *
	 * @param IShare $share The share.
	 *
	 * @return IShare The created share.
	 *
	 * @throws RuntimeException When the share could not be created (500).
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function createdShare(IShare $share): IShare {
		try {
			return $this->shares->createShare($share);
		} catch (Throwable $e) {
			throw new RuntimeException('The file request could not be sent: ' . $e->getMessage(), 500, $e);
		}
	}//end createdShare()
}//end class
