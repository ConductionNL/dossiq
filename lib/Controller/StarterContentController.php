<?php

/**
 * What a new instance starts with, read and changed from the settings screens.
 *
 *  - GET  /api/starter/shipped/{schema}          what shipped, what changed here
 *  - POST /api/starter/shipped/{schema}/{id}/adopt   take the newer version
 *  - GET  /api/starter/roles                     the shipped municipal role set
 *  - POST /api/starter/roles/adopt               take the set into use
 *  - POST /api/starter/roles/undo                put it back, while nothing uses it
 *  - POST /api/starter/case-types/{caseTypeId}/retire   stop taking new cases
 *  - POST /api/starter/case-types/{caseTypeId}/restore  offer it again
 *  - POST /api/starter/domains/{domainId}/copy   stand a domain up from another
 *  - GET  /api/starter/steps/{stepId}/used-by    the case types that use a step
 *  - DELETE /api/starter/steps/{stepId}          remove a step nobody uses
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

use OCA\Dossiq\Service\Starter\CaseTypeRetirementService;
use OCA\Dossiq\Service\Starter\DomainCopyService;
use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Service\Starter\ReusableProcessStepService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The configuration side of what ships in the box.
 *
 * Every action here changes how the instance is configured, so every one is an
 * admin setting. `#[AuthorizedAdminSetting]` rather than `#[NoAdminRequired]`
 * with a guard in the body: there is no per-object right that would let an
 * ordinary handler retire a case type or adopt a role set, so a guard here
 * would be a check that always says the same thing.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */
class StarterContentController extends Controller {

	/**
	 * The schema slugs this controller will report provenance for, and the app
	 * config key naming each one.
	 *
	 * An allow list, not a free string: `{schema}` comes off the URL, and
	 * handing an arbitrary value to the config resolver would let a caller read
	 * any schema in the register through a screen meant for the seeded ones.
	 *
	 * @var array<string, string>
	 */
	private const REPORTABLE = [
		'caseType' => 'case_type_schema',
		'roleType' => 'role_type_schema',
		'statusType' => 'status_type_schema',
		'resultType' => 'result_type_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param string                      $appName    The app name.
	 * @param IRequest                    $request    The HTTP request.
	 * @param ShippedConfigurationService $shipped    What shipped and what changed.
	 * @param MunicipalRoleSetService     $roleSet    The shipped role set.
	 * @param CaseTypeRetirementService   $retirement Retire and restore.
	 * @param DomainCopyService           $domains    The domain copy.
	 * @param ReusableProcessStepService  $steps      The shared process steps.
	 * @param LoggerInterface             $logger     Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShippedConfigurationService $shipped,
		private readonly MunicipalRoleSetService $roleSet,
		private readonly CaseTypeRetirementService $retirement,
		private readonly DomainCopyService $domains,
		private readonly ReusableProcessStepService $steps,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every seeded object of one schema, with its state.
	 *
	 * @param string $schema The schema slug, one of self::REPORTABLE.
	 *
	 * @return JSONResponse The rows, or 404 when the schema is not reported on.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function shipped(string $schema): JSONResponse {
		$objectsKey = (self::REPORTABLE[$schema] ?? '');
		if ($objectsKey === '') {
			return new JSONResponse(['error' => 'Unknown schema'], Http::STATUS_NOT_FOUND);
		}

		return $this->answered(
			run: function () use ($schema, $objectsKey): JSONResponse {
				$overview = $this->shipped->overview(targetSchema: $schema, objectsKey: $objectsKey);
				if ($overview === null) {
					// ADR-102: the register being unreachable is not an empty
					// list, and reporting it as one would tell an administrator
					// that nothing shipped.
					return new JSONResponse(
						['error' => 'The register is not available'],
						Http::STATUS_SERVICE_UNAVAILABLE
					);
				}

				return new JSONResponse(['items' => $overview, 'total' => count($overview)]);
			}
		);
	}//end shipped()

	/**
	 * Take the newer shipped version of one object.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object's id.
	 *
	 * @return JSONResponse Whether it was adopted, and why not when it was not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function adoptShipped(string $schema, string $id): JSONResponse {
		$objectsKey = (self::REPORTABLE[$schema] ?? '');
		if ($objectsKey === '') {
			return new JSONResponse(['error' => 'Unknown schema'], Http::STATUS_NOT_FOUND);
		}

		$set = (string)$this->request->getParam('set', '');
		$newShipped = $this->request->getParam('object', []);
		$accepted = ($this->request->getParam('acceptLocalChangeLoss', false) === true);

		return $this->answered(
			run: function () use ($schema, $objectsKey, $id, $set, $newShipped, $accepted): JSONResponse {
				$result = $this->shipped->adopt(
					targetSchema: $schema,
					objectsKey: $objectsKey,
					targetObject: $id,
					set: $set,
					newShipped: (is_array($newShipped) === true) ? $newShipped : [],
					accepted: $accepted,
				);

				$status = Http::STATUS_OK;
				if ($result['adopted'] === false) {
					$status = Http::STATUS_CONFLICT;
					if ($result['reason'] === 'not_found') {
						$status = Http::STATUS_NOT_FOUND;
					}
				}

				return new JSONResponse($result, $status);
			}
		);
	}//end adoptShipped()

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

				return new JSONResponse(
					$result,
					(($result['ok'] === true) ? Http::STATUS_OK : Http::STATUS_CONFLICT)
				);
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

				return new JSONResponse(
					$result,
					(($result['ok'] === true) ? Http::STATUS_OK : Http::STATUS_CONFLICT)
				);
			}
		);
	}//end undoRoles()

	/**
	 * Stop a case type taking new cases.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return JSONResponse What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function retire(string $caseTypeId): JSONResponse {
		return $this->answered(
			run: fn (): JSONResponse => $this->stateAnswer(
				result: $this->retirement->retire(caseTypeId: $caseTypeId)
			)
		);
	}//end retire()

	/**
	 * Offer a retired case type again.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return JSONResponse What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function restore(string $caseTypeId): JSONResponse {
		return $this->answered(
			run: fn (): JSONResponse => $this->stateAnswer(
				result: $this->retirement->restore(caseTypeId: $caseTypeId)
			)
		);
	}//end restore()

	/**
	 * Stand a new domain up from an existing one.
	 *
	 * @param string $domainId The domain to copy.
	 *
	 * @return JSONResponse What came along, and what did not.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function copyDomain(string $domainId): JSONResponse {
		$name = (string)$this->request->getParam('name', '');
		if (trim($name) === '') {
			return new JSONResponse(['error' => 'The new domain needs a name'], Http::STATUS_BAD_REQUEST);
		}

		return $this->answered(
			run: function () use ($domainId, $name): JSONResponse {
				$result = $this->domains->copy(domainId: $domainId, name: $name);

				$status = Http::STATUS_OK;
				if ($result['domain'] === '') {
					$status = (($result['reason'] === 'not_found')
						? Http::STATUS_NOT_FOUND
						: Http::STATUS_INTERNAL_SERVER_ERROR);
				}

				// A partial copy is not a failure and it is not a success. It
				// answers 207 so a caller that only checks for 2xx still has to
				// read `complete` to learn what it got.
				if ($status === Http::STATUS_OK && $result['complete'] === false) {
					$status = Http::STATUS_MULTI_STATUS;
				}

				return new JSONResponse($result, $status);
			}
		);
	}//end copyDomain()

	/**
	 * The case types that use one reusable step.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return JSONResponse The case types.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function stepUsedBy(string $stepId): JSONResponse {
		return $this->answered(
			run: function () use ($stepId): JSONResponse {
				$users = $this->steps->usedBy(stepId: $stepId);
				if ($users === null) {
					return new JSONResponse(
						['error' => 'The register is not available'],
						Http::STATUS_SERVICE_UNAVAILABLE
					);
				}

				return new JSONResponse(['items' => $users, 'total' => count($users)]);
			}
		);
	}//end stepUsedBy()

	/**
	 * Remove a reusable step, unless a case type still uses it.
	 *
	 * @param string $stepId The step's id.
	 *
	 * @return JSONResponse What happened, and which case type stopped it.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function deleteStep(string $stepId): JSONResponse {
		return $this->answered(
			run: function () use ($stepId): JSONResponse {
				$result = $this->steps->delete(stepId: $stepId);

				$status = Http::STATUS_OK;
				if ($result['ok'] === false) {
					$status = (($result['reason'] === 'not_found')
						? Http::STATUS_NOT_FOUND
						: Http::STATUS_CONFLICT);
				}

				return new JSONResponse($result, $status);
			}
		);
	}//end deleteStep()

	/**
	 * The answer shape retire and restore share.
	 *
	 * @param array{ok: bool, reason: string, state: string} $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function stateAnswer(array $result): JSONResponse {
		$status = Http::STATUS_OK;
		if ($result['ok'] === false) {
			$status = (($result['reason'] === 'not_found')
				? Http::STATUS_NOT_FOUND
				: Http::STATUS_CONFLICT);
		}

		return new JSONResponse($result, $status);
	}//end stateAnswer()

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
			$this->logger->error('Dossiq starter: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse(
				['error' => 'The request could not be completed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end answered()
}//end class
