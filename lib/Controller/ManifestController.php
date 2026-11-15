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
 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseTypeViewsInterface;
use OCA\Dossiq\Kernel\Contracts\MenuCaseTypesServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller resolving the user's chosen case types into a menu delta.
 *
 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-001
 */
class ManifestController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param MenuCaseTypesServiceInterface $menuCaseTypes The per-user menu choice
	 * @param IUserSession $userSession User session
	 * @param CaseTypeViewsInterface $caseTypeViews The view of the case list per case type
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MenuCaseTypesServiceInterface $menuCaseTypes,
		private readonly IUserSession $userSession,
		private readonly CaseTypeViewsInterface $caseTypeViews,
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
	 * @spec openspec/specs/case-type-navigation/spec.md#REQ-CTN-001
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

		$views = $this->viewsFor(uid: $user->getUID(), offered: $offered);
		if ($views === []) {
			return new JSONResponse(['menu' => []]);
		}

		return $this->pagesResponse(views: $views);
	}//end manifest()

	/**
	 * The case list views: the instance's declared views and the case types the user pinned.
	 *
	 * The case types a user pinned are views of the case list, which the
	 * library's "My views" section lists; no menu entry of dossiq's own.
	 *
	 * @param string            $uid     The user.
	 * @param array<int, mixed> $offered The case types offered to the user.
	 *
	 * @return array<int, mixed>
	 */
	private function viewsFor(string $uid, array $offered): array {
		$chosen = $this->menuCaseTypes->chosen(userId: $uid, visible: $offered);
		$identifiers = array_column($this->caseTypeViews->declaredViews(), 'caseTypeIdentifier');
		return array_merge(
			$this->caseTypeViews->instanceViews(byIdentifier: $this->menuCaseTypes->byIdentifier(identifiers: $identifiers)),
			$this->caseTypeViews->views(caseTypes: $chosen)
		);
	}//end viewsFor()

	/**
	 * The manifest that carries the views on the case list page.
	 *
	 * @param array<int, mixed> $views The case list views.
	 *
	 * @return JSONResponse
	 */
	private function pagesResponse(array $views): JSONResponse {
		return new JSONResponse(
			[
				'menu' => [],
				'pages' => [
					['id' => CaseTypeViewsInterface::PAGE, 'config' => [CaseTypeViewsInterface::DEFAULT_VIEWS_KEY => $views]],
				],
			]
		);
	}//end pagesResponse()
}//end class
