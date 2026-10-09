<?php

/**
 * Dossiq Menu Case Types Controller
 *
 * Reads and stores the case types the current user chose for the "My case
 * types" heading of the sidebar. Called by the "Case types in my menu" section
 * of Nextcloud's personal settings (board DqPersoonlijkeInstellingen).
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
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\MenuCaseTypesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The current user's menu case types.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
 */
class MenuCaseTypesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param MenuCaseTypesService $menuCaseTypes The per-user menu choice.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger Logs why the open-case counts are missing.
	 */
	public function __construct(
		IRequest $request,
		private readonly MenuCaseTypesService $menuCaseTypes,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The chosen case types in order, and every case type the user may add.
	 *
	 * @return JSONResponse `{chosen: [{id, title, openCases}], available: [{id, title, openCases}]}`;
	 *                      `openCases` is null when the count could not be read.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		// Authorization guard: no object id is taken; the answer is the
		// current user's own value only.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// The counts are read ONCE for the offered case types, and the chosen
		// ones are a subset of those, so they take their count from the same
		// answer (REQ-CTN-006: one aggregate query per load).
		$offered = $this->menuCaseTypes->offeredCaseTypes(userId: $user->getUID());
		try {
			$offered = $this->menuCaseTypes->withOpenCaseCounts(caseTypes: $offered);
		} catch (Throwable $e) {
			// The picker still works without its numbers; it shows none
			// rather than a 0 nobody counted.
			$this->logger->warning(
				'Dossiq menu case types: OpenRegister could not count the open cases per case type, so the picker shows no numbers',
				['exception' => $e->getMessage()]
			);
			$offered = $this->menuCaseTypes->withUnknownOpenCaseCounts(caseTypes: $offered);
		}

		return new JSONResponse(
			data: [
				'chosen' => $this->menuCaseTypes->chosen(userId: $user->getUID(), visible: $offered),
				'available' => $offered,
			]
		);
	}//end index()

	/**
	 * Store the chosen case types, in the order given.
	 *
	 * @param array<int, mixed> $ids The case type uuids in menu order.
	 *
	 * @return JSONResponse `{chosen: [{id, title}]}` as stored.
	 *
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-004
	 */
	#[NoAdminRequired]
	public function update(array $ids = []): JSONResponse {
		// Authorization guard: writes only the current user's own value, and
		// only ids of case types OpenRegister lets that user see.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$chosen = $this->menuCaseTypes->save(
			userId: $user->getUID(),
			ids: array_values($ids),
			visible: $this->menuCaseTypes->offeredCaseTypes(userId: $user->getUID())
		);

		return new JSONResponse(data: ['chosen' => $chosen]);
	}//end update()
}//end class
