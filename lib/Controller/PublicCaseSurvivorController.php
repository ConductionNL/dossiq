<?php

/**
 * Dossiq Public Case Survivor Controller.
 *
 *  - GET /api/public/case-tokens/{token}/survivor
 *
 * The one thing a "track your case" link cannot do on its own once the case
 * behind it has been merged away. OpenRegister's public token endpoint answers
 * with the object the token names, which after a merge is a case nobody works
 * on any more. The applicant holding that link is owed the status of the case
 * their request actually became part of.
 *
 * So this endpoint takes the same token, follows `mergedInto` to the end of
 * the chain, and renders the survivor through the same RBAC-respecting read
 * OpenRegister uses: only the fields the public group may read, no session, no
 * admin bypass. A token that is unknown, revoked, expired or names a case that
 * was never merged gets the same uniform 404, so the endpoint tells a caller
 * nothing they did not already hold the token for.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseMergeService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The surviving case behind a merged case's public link.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
 */
class PublicCaseSurvivorController extends Controller {
	/**
	 * OpenRegister's public token reader.
	 */
	private const TOKEN_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\CaseTokenService';

	/**
	 * Constructor.
	 *
	 * @param string           $appName         The app name.
	 * @param IRequest         $request         The HTTP request.
	 * @param CaseMergeService $mergeService    Follows `mergedInto` to its end.
	 * @param SettingsService  $settingsService Register ids and OpenRegister's services.
	 * @param LoggerInterface  $logger          Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseMergeService $mergeService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The surviving case behind this token, when the case it names was merged.
	 *
	 * @param string $token The public "track your case" token.
	 *
	 * @return JSONResponse The survivor's public projection, or a uniform 404.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function survivor(string $token): JSONResponse {
		$resolved = $this->resolveToken(token: $token);
		$objectId = (string)($resolved['object']['id'] ?? '');
		if ($objectId === '') {
			return $this->notFound();
		}

		$survivorId = $this->mergeService->resolveSurvivor(caseId: $objectId);
		if ($survivorId === '' || $survivorId === $objectId) {
			// Not a merged case. There is nothing here the public token
			// endpoint did not already answer.
			return $this->notFound();
		}

		$rendered = $this->renderPublicly(caseId: $survivorId);
		if ($rendered === null) {
			return $this->notFound();
		}

		return new JSONResponse(
			[
				'token' => $token,
				'mergedFrom' => $objectId,
				'object' => $rendered,
			]
		);
	}//end survivor()

	/**
	 * Resolve the token through OpenRegister, or answer nothing.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed> The resolved payload, empty when it did not resolve.
	 */
	private function resolveToken(string $token): array {
		$tokens = $this->settingsService->getOpenRegisterClass(class: self::TOKEN_SERVICE_CLASS);
		if ($tokens === null || method_exists($tokens, 'resolve') === false) {
			return [];
		}

		try {
			$resolved = $tokens->resolve($token);
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: a public token did not resolve: ' . $e->getMessage());
			return [];
		}

		if (is_array($resolved) === false) {
			return [];
		}

		return $resolved;
	}//end resolveToken()

	/**
	 * Render one case the way the public token endpoint renders one: RBAC
	 * enforced, no session, no admin bypass, nothing extended.
	 *
	 * @param string $caseId The surviving case.
	 *
	 * @return array<string, mixed>|null The projection, or null when it may not be read.
	 */
	private function renderPublicly(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $caseId,
				_extend: [],
				files: false,
				register: $register,
				schema: $schema,
				_rbac: true,
				_multitenancy: true
			);

			if ($entity === null) {
				return null;
			}

			$rendered = $objectService->renderEntity(
				entity: $entity,
				_extend: [],
				depth: 0,
				filter: [],
				fields: [],
				unset: [],
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			// RBAC-denied, not found, or any read failure: one answer.
			$this->logger->debug('Dossiq: a surviving case could not be read publicly: ' . $e->getMessage());
			return null;
		}

		if (is_array($rendered) === true) {
			return $rendered;
		}

		if (is_object($rendered) === true && method_exists($rendered, 'jsonSerialize') === true) {
			$serialized = $rendered->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end renderPublicly()

	/**
	 * The one answer every failure gets.
	 *
	 * @return JSONResponse The uniform 404.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
