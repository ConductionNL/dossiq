<?php

/**
 * Dossiq case reply draft controller: a reply to the resident kept as a draft.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-reply-draft/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Keeps one reply draft per user and case, for a reader of the case only.
 *
 * The draft is the user's own: it lives in their user settings, keyed by the
 * case, so nobody else reads it. The case read check runs before anything is
 * read or written.
 *
 * @spec openspec/specs/case-reply-draft/spec.md#requirement-a-reader-of-a-case-keeps-one-reply-draft-on-it
 */
class CaseReplyDraftController extends Controller {

	/**
	 * The longest draft kept, in characters.
	 */
	private const MAX_LENGTH = 20000;

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The request.
	 * @param CaseAccessGuard $caseAccessGuard The per-case access check.
	 * @param IUserSession    $userSession     The session.
	 * @param IConfig         $config          Where the user's draft is kept.
	 * @param IL10N           $l10n            The translations.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The caller's draft on the case: `text` and `savedAt`, both '' without one.
	 *
	 * @param string $id The case UUID.
	 *
	 * @return JSONResponse The draft, or the refusal.
	 *
	 * @spec openspec/specs/case-reply-draft/spec.md#requirement-a-reader-of-a-case-keeps-one-reply-draft-on-it
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->readerOf(caseId: $id);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$stored = json_decode(
			json: $this->config->getUserValue(userId: $user->getUID(), appName: Application::APP_ID, key: $this->keyOf(caseId: $id), default: ''),
			associative: true
		);
		if (is_array($stored) === false) {
			return new JSONResponse(['text' => '', 'savedAt' => '']);
		}

		return new JSONResponse(['text' => (string)($stored['text'] ?? ''), 'savedAt' => (string)($stored['savedAt'] ?? '')]);
	}//end show()

	/**
	 * Keep the text as the caller's draft on the case; an empty text clears it.
	 *
	 * @param string $id   The case UUID.
	 * @param string $text The draft.
	 *
	 * @return JSONResponse The stored draft, or the refusal.
	 *
	 * @spec openspec/specs/case-reply-draft/spec.md#requirement-a-reader-of-a-case-keeps-one-reply-draft-on-it
	 */
	#[NoAdminRequired]
	public function save(string $id, string $text=''): JSONResponse {
		$user = $this->readerOf(caseId: $id);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		if (mb_strlen(string: $text) > self::MAX_LENGTH) {
			return new JSONResponse(
				['error' => 'too-long', 'message' => $this->l10n->t('The draft is too long to keep')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$key = $this->keyOf(caseId: $id);
		if (trim(string: $text) === '') {
			$this->config->deleteUserValue(userId: $user->getUID(), appName: Application::APP_ID, key: $key);
			return new JSONResponse(['text' => '', 'savedAt' => '']);
		}

		$savedAt = (new DateTimeImmutable())->format(format: DATE_ATOM);
		$this->config->setUserValue(
			userId: $user->getUID(),
			appName: Application::APP_ID,
			key: $key,
			value: (string)json_encode(value: ['text' => $text, 'savedAt' => $savedAt])
		);

		return new JSONResponse(['text' => $text, 'savedAt' => $savedAt]);
	}//end save()

	/**
	 * The signed-in user when they may read the case, else the refusal.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return IUser|JSONResponse
	 */
	private function readerOf(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not-authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
				Http::STATUS_FORBIDDEN
			);
		}

		return $user;
	}//end readerOf()

	/**
	 * The user setting a case's draft is kept under.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The key.
	 */
	private function keyOf(string $caseId): string {
		return 'reply_draft_'.$caseId;
	}//end keyOf()
}//end class
