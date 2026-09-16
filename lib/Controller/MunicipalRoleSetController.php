<?php

/**
 * The shipped municipal role set: offered, adopted, and put back.
 *
 *  - GET  /api/starter/roles        the shipped set, and whether it is in use
 *  - POST /api/starter/roles/adopt  take the set into use
 *  - POST /api/starter/roles/undo   put it back, while nothing uses it
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Its own controller rather than three more methods on
 * {@see StarterContentController}, which the analyser was right about: that
 * class had grown eleven public methods over four unrelated nouns. The role set
 * is one noun with one lifecycle, and it reads better alone.
 *
 * Every action is an admin setting. There is no per-object right that would let
 * an ordinary handler adopt a role set, so a guard in the body would be a check
 * that always says the same thing.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class MunicipalRoleSetController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName The app name.
	 * @param IRequest                $request The HTTP request.
	 * @param MunicipalRoleSetService $roleSet The shipped role set.
	 * @param LoggerInterface         $logger  Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MunicipalRoleSetService $roleSet,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The shipped municipal role set, and whether it is in use.
	 *
	 * @return JSONResponse The offer.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function roles(): JSONResponse {
		return $this->answered(
			run: function (): JSONResponse {
				$offer = $this->roleSet->offer();
				if ($offer === null) {
					// ADR-102: the register being unreachable is not an empty
					// set, and reporting it as one would tell an administrator
					// that dossiq ships no roles.
					return new JSONResponse(
						['error' => 'The register is not available'],
						Http::STATUS_SERVICE_UNAVAILABLE
					);
				}

				return new JSONResponse($offer);
			}
		);
	}//end roles()

	/**
	 * Take the shipped role set into use.
	 *
	 * @return JSONResponse What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function adoptRoles(): JSONResponse {
		return $this->answered(
			run: function (): JSONResponse {
				$result = $this->roleSet->adopt();

				return new JSONResponse($result, $this->okOrConflict(result: $result));
			}
		);
	}//end adoptRoles()

	/**
	 * Put the shipped role set back to dormant.
	 *
	 * @return JSONResponse What happened, and which role stopped it when it did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function undoRoles(): JSONResponse {
		return $this->answered(
			run: function (): JSONResponse {
				$result = $this->roleSet->undoAdoption();

				return new JSONResponse($result, $this->okOrConflict(result: $result));
			}
		);
	}//end undoRoles()

	/**
	 * 200 when the act was performed, 409 when the state refused it.
	 *
	 * @param array{ok: bool} $result The service's answer.
	 *
	 * @return integer The HTTP status.
	 */
	private function okOrConflict(array $result): int {
		if ($result['ok'] === true) {
			return Http::STATUS_OK;
		}

		return Http::STATUS_CONFLICT;
	}//end okOrConflict()

	/**
	 * Run one action, turning anything thrown into a 500 that says nothing
	 * about the stack.
	 *
	 * @param callable(): JSONResponse $run The action.
	 *
	 * @return JSONResponse The response.
	 */
	private function answered(callable $run): JSONResponse {
		try {
			return $run();
		} catch (Throwable $e) {
			$this->logger->error('Dossiq starter roles: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse(
				['error' => 'The request could not be completed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end answered()
}//end class
