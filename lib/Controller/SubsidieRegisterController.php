<?php

/**
 * Procest Subsidieregister Controller.
 *
 * Public Wet open overheid (art. 3.3 lid 2 onder f) subsidieregister feed
 * (REQ-SUB-006). This is a deliberately public, read-only endpoint: it emits
 * ONLY granted/settled subsidies with natuurlijke personen anonymised per the
 * VNG AVG-richtlijn. It never exposes draft decisions, bewijsstukken, BSNs, or
 * any internal dossier data. No write operation is reachable here.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\SubsidieRegisterExporter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Public subsidieregister feed controller.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-39
 */
class SubsidieRegisterController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param SubsidieRegisterExporter $exporter Feed builder.
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsService $settingsService,
		private readonly SubsidieRegisterExporter $exporter,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Export the public subsidieregister feed (anonymised, published only).
	 *
	 * @return JSONResponse
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-39
	 */
	public function export(): JSONResponse {
		$limit = (int)$this->request->getParam('limit', 100);
		$offset = (int)$this->request->getParam('offset', 0);

		$entries = $this->collectEntries();

		return new JSONResponse($this->exporter->buildFeed($entries, $limit, $offset));
	}//end export()

	/**
	 * Collect feed entries from granted/settled decisions, joining their
	 * aanvraag and regeling. Returns an empty list when OpenRegister is
	 * unavailable rather than leaking errors to the public.
	 *
	 * @return array<int, array<string, mixed>> The feed entries.
	 */
	private function collectEntries(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$config = $this->resolveRegisterConfig();
		if ($config === null) {
			return [];
		}

		$register = $config['register'];
		$beschikkingSchema = $config['beschikkingSchema'];
		$aanvraagSchema = $config['aanvraagSchema'];
		$regelingSchema = $config['regelingSchema'];

		try {
			$beschikkingen = $objectService->findAll(
				['filters' => ['register' => (int)$register, 'schema' => (int)$beschikkingSchema, 'status' => 'verleend']]
			);
		} catch (Throwable $e) {
			return [];
		}

		$entries = [];
		foreach ($beschikkingen as $beschikking) {
			$beschikking = $this->toArray(value: $beschikking);
			$aanvraagId = (string)($beschikking['subsidieaanvraag'] ?? '');
			if ($aanvraagId === '') {
				continue;
			}

			$aanvraag = $this->safeFind(objectService: $objectService, register: $register, schema: $aanvraagSchema, id: $aanvraagId);
			$regeling = [];
			$regelingId = (string)($aanvraag['subsidieregeling'] ?? '');
			if ($regelingId !== '') {
				$regeling = $this->safeFind(objectService: $objectService, register: $register, schema: $regelingSchema, id: $regelingId);
			}

			$entries[] = $this->exporter->toFeedEntry($aanvraag, $regeling, $beschikking);
		}//end foreach

		return $entries;
	}//end collectEntries()

	/**
	 * Resolve and validate the register/schema configuration for the feed.
	 *
	 * @return array{register: string, beschikkingSchema: string, aanvraagSchema: string, regelingSchema: string}|null
	 *                                                                                                                 The validated config, or null when any required value is unset.
	 */
	private function resolveRegisterConfig(): ?array {
		$register = $this->settingsService->getConfigValue('register');
		$beschikkingSchema = $this->settingsService->getConfigValue('subsidie_beschikking_schema');
		$aanvraagSchema = $this->settingsService->getConfigValue('subsidie_aanvraag_schema');
		$regelingSchema = $this->settingsService->getConfigValue('subsidie_regeling_schema');
		if ($register === '' || $beschikkingSchema === '' || $aanvraagSchema === '' || $regelingSchema === '') {
			return null;
		}

		return [
			'register' => $register,
			'beschikkingSchema' => $beschikkingSchema,
			'aanvraagSchema' => $aanvraagSchema,
			'regelingSchema' => $regelingSchema,
		];
	}//end resolveRegisterConfig()

	/**
	 * Find an object defensively, returning an empty array on any failure.
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed> The object or an empty array.
	 */
	private function safeFind(object $objectService, string $register, string $schema, string $id): array {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
			return $this->toArray(value: $found);
		} catch (Throwable $e) {
			return [];
		}
	}//end safeFind()

	/**
	 * Normalise an OpenRegister result (entity or array) into an array.
	 *
	 * @param mixed $value The result.
	 *
	 * @return array<string, mixed> The array form.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
