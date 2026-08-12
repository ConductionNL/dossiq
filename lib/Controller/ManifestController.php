<?php

/**
 * Procest Manifest Controller
 *
 * Serves the backend `/api/manifest` delta consumed by the frontend's
 * `useAppManifest('procest', bundled, { mergeStrategy: 'delta' })`. It resolves
 * the live `caseType` OpenRegister objects the current user may see and returns
 * one navigation child per case type, keyed for a delta-merge into the existing
 * "Cases" menu group (ADR-036 keyed `children[]` merge). This is the sanctioned
 * imperative seam (ADR-031): a case type is a live OpenRegister object, so the
 * per-case-type nav entries are resolved server-side at request time rather than
 * baked into the static bundled manifest.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-navigation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller resolving case types into a menu delta for the app shell.
 *
 * @spec openspec/changes/case-type-navigation/tasks.md
 */
class ManifestController extends Controller {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param SettingsService $settingsService Settings service
	 * @param IUserSession $userSession User session
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the case-type navigation delta.
	 *
	 * Resolves the live `caseType` objects visible to the current user (RBAC is
	 * enforced by OpenRegister's ObjectService under the user session) and maps
	 * each to a menu child under the existing `CasesGroup`. An unauthenticated
	 * caller is refused with 401. Otherwise the response is a no-op delta
	 * (`['menu' => []]`) whenever OpenRegister is unavailable, the register/schema
	 * is unconfigured, or no case types exist — it must never break the app shell.
	 *
	 * @return JSONResponse A `mergeStrategy: 'delta'` menu payload.
	 *
	 * @spec openspec/changes/case-type-navigation/tasks.md
	 */
	#[NoAdminRequired]
	public function manifest(): JSONResponse {
		// Authorization guard: the endpoint is authenticated-user scoped (it
		// never takes an object id — it returns only the case types the CURRENT
		// user may see, RBAC-filtered by OpenRegister's ObjectService). An
		// unauthenticated caller (NC middleware normally rejects these first) is
		// refused explicitly; the frontend treats the non-200 as "no override".
		if ($this->userSession->getUser() === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return new JSONResponse(['menu' => []]);
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return new JSONResponse(['menu' => []]);
		}

		$caseTypes = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 200]
		);

		if (count($caseTypes) === 0) {
			return new JSONResponse(['menu' => []]);
		}

		// Sort deterministically by human name so nav order is stable across
		// requests regardless of the store's return order.
		usort(
			$caseTypes,
			static function (array $left, array $right): int {
				return strcasecmp(
					(string)($left['title'] ?? ''),
					(string)($right['title'] ?? '')
				);
			}
		);

		$children = [];
		$index = 0;
		foreach ($caseTypes as $caseType) {
			$uuid = $this->resolveUuid(object: $caseType);
			if ($uuid === null) {
				continue;
			}

			$label = (string)($caseType['title'] ?? $uuid);

			$children[] = [
				'id' => 'ct-' . $uuid,
				'label' => $label,
				'icon' => 'icon-folder',
				'route' => 'Cases',
				'query' => ['caseType' => $uuid],
				'order' => (50 + $index),
			];
			$index++;
		}//end foreach

		if (count($children) === 0) {
			return new JSONResponse(['menu' => []]);
		}

		return new JSONResponse(
			[
				'menu' => [
					[
						'id' => 'CasesGroup',
						'children' => $children,
					],
				],
			]
		);
	}//end manifest()

	/**
	 * Resolve an OpenRegister object's UUID from its array shape.
	 *
	 * ObjectService rows expose their identifier either at the top level as
	 * `id`/`uuid` or nested under the `@self` metadata block, depending on the
	 * search path. Falls back through all three before giving up.
	 *
	 * @param array<string, mixed> $object The object as an associative array.
	 *
	 * @return string|null The UUID, or null when none is resolvable.
	 *
	 * @spec openspec/changes/case-type-navigation/tasks.md
	 */
	private function resolveUuid(array $object): ?string {
		$self = ($object['@self'] ?? null);

		$candidates = [
			($object['uuid'] ?? null),
			($object['id'] ?? null),
		];

		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}

			if (is_int($candidate) === true) {
				return (string)$candidate;
			}
		}

		return null;
	}//end resolveUuid()
}//end class
