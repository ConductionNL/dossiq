<?php

/**
 * Dossiq PackageController
 *
 * Lists the case definition packages this app ships and installs one when an
 * administrator asks. A package marked `install: on-request` never installs
 * on upgrade; this is how an administrator asks for it without a shell
 * (`occ dossiq:package:install <id>` is the other way).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The shipped package catalogue and the on-request install.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class PackageController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param PackageInstaller $installer   Installs and lists the packages.
	 * @param IUserSession     $userSession The session, for the body-side guard.
	 */
	public function __construct(
		IRequest $request,
		private readonly PackageInstaller $installer,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every shipped package, with its version, install mode and the version installed here.
	 *
	 * @return JSONResponse `{ results: [...] }`.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['results' => $this->installer->catalogue()]);
	}//end index()

	/**
	 * Install one package.
	 *
	 * Installed and already-current answer 200. A package that is unknown,
	 * invalid or needs a capability this instance lacks answers 422 with the
	 * installer's reason; a write that failed and was taken back answers 500.
	 *
	 * @param string $id The package id.
	 *
	 * @return JSONResponse The installer's outcome.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function install(string $id): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		$outcome = $this->installer->install(id: $id);
		$status = match ($outcome['status']) {
			PackageInstaller::INSTALLED, PackageInstaller::CURRENT => Http::STATUS_OK,
			PackageInstaller::REFUSED => Http::STATUS_UNPROCESSABLE_ENTITY,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};

		return new JSONResponse($outcome, $status);
	}//end install()

	/**
	 * Reload the shipped templates: install every package that is installed
	 * here or installs automatically.
	 *
	 * AuthorizedAdminSetting refuses a non-admin in the middleware, before
	 * this runs; a request without a user is refused before the installer
	 * reads or writes anything.
	 *
	 * @return JSONResponse `{ results: [...] }`, one outcome per package; 500 when one failed.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-administrator-reloads-the-shipped-templates
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function reload(): JSONResponse {
		$refusal = $this->requireUser();
		if ($refusal !== null) {
			return $refusal;
		}

		$outcomes = $this->installer->reloadInstalled();
		$status = Http::STATUS_OK;
		if (in_array(PackageInstaller::FAILED, array_column($outcomes, 'status'), true) === true) {
			$status = Http::STATUS_INTERNAL_SERVER_ERROR;
		}

		return new JSONResponse(['results' => $outcomes], $status);
	}//end reload()

	/**
	 * Refuse a request without a user. AuthorizedAdminSetting decides
	 * admin-ness in the middleware; this is the body-side check.
	 *
	 * @return JSONResponse|null Null when there is a user.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()
}//end class
