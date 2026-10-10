<?php

/**
 * Dossiq Manifest Controller
 *
 * Serves the backend `/api/manifest` delta consumed by the frontend's
 * `useAppManifest('dossiq', bundled, { mergeStrategy: 'delta' })`. It returns
 * the caption "My case types" and one entry per case type the current user
 * CHOSE for their menu, in their order (board DqZijbalk, change
 * case-types-in-my-menu). This is the sanctioned imperative seam (ADR-031): the
 * choice is per user and a case type is a live OpenRegister object, so the
 * entries are resolved server-side at request time rather than baked into the
 * static bundled manifest.
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

use OCA\Dossiq\Service\MenuCaseTypesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Controller resolving the user's chosen case types into a menu delta.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-001
 */
class ManifestController extends Controller {

	/**
	 * Menu order of the "My case types" caption; the entries follow from 31.
	 *
	 * @var int
	 */
	private const CAPTION_ORDER = 30;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param MenuCaseTypesService $menuCaseTypes The per-user menu choice
	 * @param IUserSession $userSession User session
	 * @param IURLGenerator $urlGenerator URL generator (personal settings link)
	 * @param IL10N $l10n Translations (the caption label)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MenuCaseTypesService $menuCaseTypes,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the case-type navigation delta.
	 *
	 * The caption "My case types" (with `href` to the Dossiq section of
	 * Nextcloud's personal settings) and one entry per chosen case type, each
	 * opening the Cases list filtered on it. An unauthenticated caller is
	 * refused with 401. Otherwise the response is a no-op delta
	 * (`['menu' => []]`) whenever OpenRegister is unavailable, the
	 * register/schema is unconfigured, or no case types exist; it must never
	 * break the app shell.
	 *
	 * @return JSONResponse A `mergeStrategy: 'delta'` menu payload.
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

		$visible = $this->menuCaseTypes->visibleCaseTypes();
		if (count($visible) === 0) {
			return new JSONResponse(['menu' => []]);
		}

		$menu = [
			[
				'id' => 'MyCaseTypesCaption',
				'type' => 'caption',
				'label' => $this->l10n->t('My case types'),
				'order' => self::CAPTION_ORDER,
				'href' => $this->urlGenerator->linkToRoute(
					routeName: 'settings.PersonalSettings.index',
					arguments: ['section' => 'dossiq']
				),
			],
		];

		$chosen = $this->menuCaseTypes->chosen(userId: $user->getUID(), visible: $visible);
		foreach ($chosen as $index => $caseType) {
			$menu[] = [
				'id' => 'ct-' . $caseType['id'],
				'label' => $caseType['title'],
				'icon' => 'FolderOutline',
				'route' => 'Cases',
				'query' => ['caseType' => $caseType['id']],
				'order' => (self::CAPTION_ORDER + 1 + $index),
			];
		}

		return new JSONResponse(['menu' => $menu]);
	}//end manifest()
}//end class
