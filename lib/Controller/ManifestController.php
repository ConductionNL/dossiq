<?php

/**
 * Dossiq Manifest Controller
 *
 * Serves the backend `/api/manifest` delta consumed by the frontend's
 * `useAppManifest('dossiq', bundled, { mergeStrategy: 'delta' })`. It returns
 * one default view of the Cases page per case type the current user pinned
 * (case-type-pins-are-views), which the library's "My views" section links to.
 * This is the sanctioned imperative seam (ADR-031): the choice is per user and
 * a case type is a live OpenRegister object, so the views are resolved
 * server-side at request time rather than baked into the static bundled
 * manifest.
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
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseTypeViews;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller resolving the user's chosen case types into a menu delta.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */
class ManifestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param MenuCaseTypesService $menuCaseTypes The per-user menu choice
	 * @param IUserSession $userSession User session
	 * @param CaseTypeViews $caseTypeViews The view of the case list per case type
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MenuCaseTypesService $menuCaseTypes,
		private readonly IUserSession $userSession,
		private readonly CaseTypeViews $caseTypeViews,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the case-type views delta.
	 *
	 * One default view of the Cases page per case type the user pinned (the
	 * Woo request case type has a view of its own on the page), added to the
	 * page's default views. An unauthenticated caller is refused with 401.
	 * Otherwise the response is a no-op delta (`['menu' => []]`) whenever
	 * OpenRegister is unavailable, the register/schema is unconfigured, or no
	 * case types exist; it must never break the app shell.
	 *
	 * @return JSONResponse A `mergeStrategy: 'delta'` payload.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
	 */
	#[NoAdminRequired]
	public function manifest(): JSONResponse {
		// Authorization guard: the endpoint is scoped to the current user (it
		// takes no object id and returns only that user's own choice, filtered
		// to the case types OpenRegister lets them see).
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$offered = $this->menuCaseTypes->offeredCaseTypes(userId: $user->getUID());
		if (count($offered) === 0) {
			return new JSONResponse(['menu' => []]);
		}

		// The case types a user pinned are views of the case list, which the
		// library's "My views" section lists; no menu entry of dossiq's own.
		$chosen = $this->menuCaseTypes->chosen(userId: $user->getUID(), visible: $offered);
		$identifiers = array_column($this->caseTypeViews->declaredViews(), 'caseTypeIdentifier');
		$views = array_merge(
			$this->caseTypeViews->instanceViews(byIdentifier: $this->menuCaseTypes->byIdentifier(identifiers: $identifiers)),
			$this->caseTypeViews->views(caseTypes: $chosen)
		);
		if ($views === []) {
			return new JSONResponse(['menu' => []]);
		}

		return new JSONResponse(
			[
				'menu' => [],
				'pages' => [
					['id' => CaseTypeViews::PAGE, 'config' => [CaseTypeViews::DEFAULT_VIEWS_KEY => $views]],
				],
			]
		);
	}//end manifest()
}//end class
