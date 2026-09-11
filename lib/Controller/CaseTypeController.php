<?php

/**
 * Dossiq Case Type Controller.
 *
 * The two things about a case type that a page cannot work out for itself: the
 * effective blueprint of a type that derives from a parent, and the
 * validate-then-publish gesture.
 *
 * Separate from `CaseDefinitionController`, which owns the portable ZIP
 * package (export, import, validate a bundle, copy, delete). These two are
 * keyed on a case type rather than on a package, and folding them in there
 * took that controller to fourteen collaborators, which phpmd refuses and a
 * reader would too.
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
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseTypePublishService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case type's effective blueprint, and publishes a draft.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                 $appName        The app name.
	 * @param IRequest               $request        The request.
	 * @param CaseTypeResolver       $resolver       The effective blueprint of a case type.
	 * @param CaseTypePublishService $publishService Validates and publishes a draft.
	 * @param LoggerInterface        $logger         The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTypeResolver $resolver,
		private readonly CaseTypePublishService $publishService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The effective blueprint of a case type: its own rows and its parent's.
	 *
	 * A page cannot compute this. `statusType where caseType = X` answers a
	 * CHILD type's own rows, and a child that derives its lifecycle from a
	 * parent has none — an empty list, not an error. So the merge happens here
	 * and the page renders what it is given, with `origin` on every row saying
	 * where it came from.
	 *
	 * `#[NoAdminRequired]` and no body guard, and the two go together: this
	 * READS a case type, which the case page, the stepper and the new-case
	 * form all do as ordinary users. Nothing here writes, and OpenRegister
	 * applies its own read authority underneath.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse The blueprint, or 404 when the type is unreadable.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	#[NoAdminRequired]
	public function blueprint(string $id): JSONResponse {
		$blueprint = $this->resolver->blueprintFor(caseTypeId: $id);

		if (($blueprint['caseType'] ?? []) === []) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($blueprint);
	}//end blueprint()

	/**
	 * Validate a draft case type and publish it.
	 *
	 * Two answers, not one: the findings and a 422 when the draft is not
	 * ready, or the published version. Validation runs before the FIRST write,
	 * not between the writes: a publish that half-ran leaves the case type
	 * published and its workflow template a draft, and nothing afterwards says
	 * which half ran.
	 *
	 * 🔴 THE AUTHORITY IS THE ATTRIBUTE, NOT A GUARD IN THE BODY. This was
	 * written as `#[NoAdminRequired]` plus a `requireAdmin()` in the body, and
	 * hydra gate-9 (semantic-auth) refused it: an attribute saying "any
	 * authenticated user may reach this" over a body that admits only admins
	 * is exactly the mismatch that gate exists for, and it reads to a reviewer
	 * as an endpoint anyone may call. `AuthorizedAdminSetting` says what is
	 * true, and Nextcloud's own middleware enforces it before the method runs
	 * — which is one fewer place for the check to be forgotten.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function publish(string $id): JSONResponse {
		try {
			$result = $this->publishService->publish(
				caseTypeId: $id,
				changeNote: (string)$this->request->getParam('changeNote', '')
			);
		} catch (Throwable $e) {
			$this->logger->error('Case type publish failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($result['published'] === false) {
			return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result);
	}//end publish()

	/**
	 * What stands between a draft and being published, without publishing it.
	 *
	 * The Publish dialog asks this before it asks for a change note: a person
	 * who is about to be refused should be told so before being made to write
	 * the note.
	 *
	 * Admin-only, like the publish itself: otherwise it is a way for any
	 * authenticated user to enumerate what is wrong with every case type in
	 * the install.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse `{findings: string[]}`.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function validatePublish(string $id): JSONResponse {
		return new JSONResponse(['findings' => $this->publishService->validate(caseTypeId: $id)]);
	}//end validatePublish()

}//end class
