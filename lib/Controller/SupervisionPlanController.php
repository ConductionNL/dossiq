<?php

/**
 * A supervision plan's progress and a proposed schedule, read only.
 *
 * Both answers are read with the reader's own access: the plan, its categories
 * and the inspections are searched in the reader's scope, so a reader who may
 * not see an inspection does not see it counted either. Neither endpoint
 * writes: "Suggest a schedule" shows its proposal first, and planning the
 * inspections it proposes is a separate, deliberate act.
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
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read a supervision plan's figures and propose its schedule.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */
class SupervisionPlanController extends Controller {
	use SearchesObjects;

	/**
	 * The app config keys of the three schemas (SchemaSlugMap).
	 */
	private const SCHEMA_KEYS = [
		'plan'       => 'supervision_plan_schema',
		'category'   => 'supervision_plan_category_schema',
		'inspection' => 'field_inspection_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param SettingsServiceInterface    $settings    The object service and the register.
	 * @param SupervisionPlanProgress     $progress    Counts the plan's inspections.
	 * @param SupervisionScheduleProposal $proposal    Proposes the schedule.
	 * @param ITimeFactory                $time        Today, for the proposal's first day.
	 * @param IUserSession                $userSession The session.
	 * @param LoggerInterface             $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsServiceInterface $settings,
		private readonly SupervisionPlanProgress $progress,
		private readonly SupervisionScheduleProposal $proposal,
		private readonly ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The plan's figures: totals, per category and per quarter.
	 *
	 * @param string $id The supervisionPlan uuid.
	 *
	 * @return JSONResponse The figures, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function progress(string $id): JSONResponse {
		return $this->answer(
			id: $id,
			op: 'supervision-plan-progress',
			build: fn (array $plan, array $categories, string $inspectionSchema): array => $this->progress->measure(
				plan: $plan,
				categories: $categories,
				inspections: $this->inspectionsOf(categories: $categories, schema: $inspectionSchema)
			)
		);
	}//end progress()

	/**
	 * A proposed schedule for the plan; nothing is planned.
	 *
	 * The optional `from` (Y-m-d) is the first day a date may fall on; today
	 * when it is absent or not a date.
	 *
	 * @param string $id The supervisionPlan uuid.
	 *
	 * @return JSONResponse The proposal, or the refusal.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function scheduleProposal(string $id): JSONResponse {
		return $this->answer(
			id: $id,
			op: 'supervision-plan-proposal',
			build: fn (array $plan, array $categories): array => $this->proposal->propose(
				plan: $plan,
				categories: $categories,
				from: $this->firstDay(from: (string)$this->request->getParam('from', ''))
			)
		);
	}//end scheduleProposal()

	/**
	 * Read the plan and its categories, then build the answer from them.
	 *
	 * @param string   $id    The plan uuid.
	 * @param string   $op    The operation, for the log.
	 * @param callable $build Builds the payload from the plan, its categories and the inspection schema.
	 *
	 * @return JSONResponse The payload, 401, 404 or 503.
	 */
	private function answer(string $id, string $op, callable $build): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$objectService = $this->settings->getObjectService();
		$register      = $this->settings->getConfigValue('register');
		$schemas       = array_map(fn (string $key): string => $this->settings->getConfigValue($key), self::SCHEMA_KEYS);
		if ($objectService === null || $register === '' || in_array('', $schemas, true) === true) {
			return new JSONResponse(['error' => 'The supervision plan schemas are not configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		try {
			$plan = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schemas['plan'], id: $id);
			if ($plan === null) {
				return new JSONResponse(['error' => 'Supervision plan not found'], Http::STATUS_NOT_FOUND);
			}

			$categories = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schemas['category'],
				filters: ['plan' => $id]
			);

			return new JSONResponse($build($plan, $categories, $schemas['inspection']));
		} catch (Throwable $e) {
			$this->logger->error('dossiq: '.$op.' failed', ['exception' => $e, 'plan' => $id]);
			return new JSONResponse(['error' => 'The supervision plan could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end answer()

	/**
	 * The field inspections that name one of the categories, read in the reader's scope.
	 *
	 * @param list<array<string, mixed>> $categories The categories.
	 * @param string                     $schema     The field inspection schema.
	 *
	 * @return list<array<string, mixed>> The inspections.
	 */
	private function inspectionsOf(array $categories, string $schema): array {
		$objectService = $this->settings->getObjectService();
		$register      = $this->settings->getConfigValue('register');
		$inspections   = [];
		foreach ($categories as $category) {
			$categoryId = SupervisionPlanProgress::idOf(record: $category);
			if ($categoryId === '' || $objectService === null) {
				continue;
			}

			$inspections = array_merge(
				$inspections,
				$this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['planCategory' => $categoryId]
				)
			);
		}

		return $inspections;
	}//end inspectionsOf()

	/**
	 * The proposal's first day: the given Y-m-d, else today.
	 *
	 * @param string $from The requested day.
	 *
	 * @return DateTimeImmutable The day.
	 */
	private function firstDay(string $from): DateTimeImmutable {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
		if ($day !== false && $day->format('Y-m-d') === $from) {
			return $day;
		}

		return DateTimeImmutable::createFromInterface($this->time->now());
	}//end firstDay()
}//end class
