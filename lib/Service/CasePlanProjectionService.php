<?php

/**
 * Project a dossiq `caseModel` onto OpenRegister's case layer.
 *
 * The app keeps `caseModel` as authoring data and stops keeping a runtime for
 * it. OpenRegister has no `caseModel` entity: a case's plan items are rows
 * created for that case. So the definition is projected at case start, once,
 * into `CasePlanService::createPlan()`, and every later read and transition
 * goes to OpenRegister.
 *
 * THIS IS A MAPPING, NOT AN ENGINE. Nothing here decides a lifecycle, fires a
 * sentry or cascades. It translates one vocabulary into another and refuses
 * what it cannot translate, in the shape of
 * {@see \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator}.
 *
 * IT RESOLVES THE PUBLISHED MODEL ITSELF. dossiq holds no case engine: the
 * local runtime is gone (retire-cmmn-caseplanstate task 3.1) and
 * `CmmnRetirementTest` refuses a new one. The `caseModel` stays authoring
 * data that this class reads and hands to OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\Contracts\CasePlanProjectionServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Converts a published `caseModel` into an OpenRegister case-plan definition
 * and creates the plan for one case.
 *
 * The conversion itself, every refusal of a shape it declines to guess at,
 * lives in the pure {@see CaseModelConverter} and {@see SentryConverter},
 * which this class builds; it keeps the reads, the plan creation and the
 * three public conversion entry points, which delegate.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
class CasePlanProjectionService implements CasePlanProjectionServiceInterface {

	use SearchesObjects;

	/**
	 * OpenRegister's case layer, resolved by name.
	 *
	 * Named as a constant because getting it wrong is SILENT: the container
	 * throws, the projection reports "OpenRegister exposes no case layer" and
	 * the case starts with no plan, which reads as "this caseType has no
	 * model" rather than "I looked in the wrong place". The sibling
	 * {@see \OCA\Dossiq\Service\Support\ProjectsOntoFlows} lost its
	 * `\Flow\` segment exactly that way.
	 *
	 * @var string
	 */
	public const CASE_PLAN_SERVICE = 'OCA\\OpenRegister\\Service\\Case\\CasePlanService';

	/**
	 * The closed if-part operator table (design.md section 2); kept here for callers, owned by {@see SentryConverter}.
	 *
	 * @var array<string, string>
	 */
	public const OPERATORS = SentryConverter::OPERATORS;

	/**
	 * dossiq `onPart.standardEvent` to the OpenRegister flow event catalog; owned by {@see SentryConverter}.
	 *
	 * @var array<string, string>
	 */
	public const STANDARD_EVENTS = SentryConverter::STANDARD_EVENTS;

	/**
	 * The catalog event an ordinary write to the case object dispatches; owned by {@see SentryConverter}.
	 *
	 * @var string
	 */
	public const OBJECT_WRITE_EVENT = SentryConverter::OBJECT_WRITE_EVENT;

	/**
	 * The app id dossiq acts as when it calls the case layer from its own
	 * in-process code (a listener, a command, a repair step).
	 *
	 * OpenRegister refuses a plan created or read with no acting identity
	 * ("no acting identity" / "no case plan"), so the bridge's first version,
	 * which passed `uid: null`, was a silent no-op: every case start logged
	 * an error and created nothing. The system verbs act as `system:dossiq`
	 * instead (openregister one-engine-bpmn-and-cmmn, PR #4544).
	 *
	 * @var string
	 */
	public const SYSTEM_APP = 'dossiq';

	/**
	 * Converts one sentry, its on-part and its if-part.
	 *
	 * @var SentryConverter
	 */
	private readonly SentryConverter $sentries;

	/**
	 * Converts a whole caseModel into a case-plan definition.
	 *
	 * @var CaseModelConverter
	 */
	private readonly CaseModelConverter $converter;

	/**
	 * Reads the rows a caseType or caseModel lookup answers.
	 *
	 * @var CaseModelSearchHits
	 */
	private readonly CaseModelSearchHits $hits;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Bridge to OpenRegister and config.
	 * @param LoggerInterface   $logger          Logger.
	 * @param IUserSession|null $userSession     Who is signed in; nobody means a background
	 *                                           run, which reads as the system.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
	) {
		$this->sentries = new SentryConverter();
		$this->converter = new CaseModelConverter(sentries: $this->sentries);
		$this->hits = new CaseModelSearchHits();
	}//end __construct()

	/**
	 * Convert a whole `caseModel` into an OpenRegister case-plan definition.
	 *
	 * Pure: no I/O, no container. The flat `planItems` list with `parentId`
	 * links becomes the nested `items` tree OpenRegister validates, and every
	 * sentry is converted through the closed table.
	 *
	 * @param array<string, mixed> $caseModel The published caseModel object.
	 *
	 * @return array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} The definition.
	 *
	 * @throws UnexpectedValueException Naming the construct it refuses to convert.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertModel(array $caseModel): array {
		return $this->converter->convertModel(caseModel: $caseModel);
	}//end convertModel()

	/**
	 * Convert one dossiq sentry into an OpenRegister sentry.
	 *
	 * @param array<string, mixed> $sentry The dossiq `{id?, onPart?, ifPart?}` sentry.
	 * @param string               $where  Which criteria list, for the refusal message.
	 *
	 * @return array<string, mixed> The OpenRegister `{id?, on?, if?}` sentry.
	 *
	 * @throws UnexpectedValueException Naming the part it refuses.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertSentry(array $sentry, string $where): array {
		return $this->sentries->convertSentry(sentry: $sentry, where: $where);
	}//end convertSentry()

	/**
	 * Convert a dossiq `{field, operator, value}` if-part into JSONLogic.
	 *
	 * The table is closed (design.md section 2). An operator it does not name
	 * is refused, never guessed: `FlowExpression::isTrue()` answers false for
	 * an expression it cannot evaluate, so a mistranslated condition would
	 * look exactly like a sentry that has not fired yet.
	 *
	 * @param array<string, mixed> $ifPart The dossiq if-part.
	 * @param string               $where  Which sentry, for the refusal message.
	 *
	 * @return array<string, mixed> The JSONLogic rule.
	 *
	 * @throws UnexpectedValueException Naming the operator or the missing field.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function convertIfPart(array $ifPart, string $where): array {
		return $this->sentries->convertIfPart(ifPart: $ifPart, where: $where);
	}//end convertIfPart()

	/**
	 * Project a case by id, resolving its caseType first.
	 *
	 * The entry point the case-created listener calls, so the listener stays
	 * a pure observer and every decision lives here (ADR-022).
	 *
	 * @param string $caseId     The case object uuid.
	 * @param string $caseTypeId The caseType uuid the case names.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function projectForCase(string $caseId, string $caseTypeId): array {
		$caseType = $this->findCaseType(caseTypeId: $caseTypeId);
		if ($caseType === null) {
			return ['projected' => false, 'reason' => 'case_type_not_found'];
		}

		return $this->projectAtCaseStart(caseId: $caseId, caseType: $caseType);
	}//end projectForCase()

	/**
	 * Project the published caseModel of a CMMN-managed case onto OpenRegister.
	 *
	 * Called at case start. Does nothing and says so when the caseType is not
	 * CMMN-managed, when no model is published (no published model
	 * means an empty plan) or when OpenRegister already
	 * holds a plan for this case, so a re-run never mints a second plan.
	 *
	 * @param string               $caseId   The case object uuid.
	 * @param array<string, mixed> $caseType The caseType object.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function projectAtCaseStart(string $caseId, array $caseType): array {
		if ((string)($caseType['handlingModel'] ?? 'bpmn') !== 'cmmn') {
			return ['projected' => false, 'reason' => 'case_not_cmmn_managed'];
		}

		$plans = $this->settingsService->getOpenRegisterClass(class: self::CASE_PLAN_SERVICE);
		if ($plans === null) {
			$this->logger->warning('CasePlanProjectionService: OpenRegister exposes no case layer', ['caseId' => $caseId]);
			return ['projected' => false, 'reason' => 'case_layer_unavailable'];
		}

		$caseTypeId = trim((string)($caseType['id'] ?? $caseType['uuid'] ?? ''));
		$model = $this->findPublishedModel(caseTypeId: $caseTypeId);
		if ($model === null) {
			return ['projected' => false, 'reason' => 'no_published_case_model'];
		}

		try {
			$definition = $this->convertModel(caseModel: $model);
		} catch (UnexpectedValueException $e) {
			$this->logger->error(
				'CasePlanProjectionService: refused to project a caseModel',
				['caseId' => $caseId, 'caseType' => $caseTypeId, 'reason' => $e->getMessage()]
			);
			return ['projected' => false, 'reason' => $e->getMessage()];
		}

		return $this->createPlan(plans: $plans, caseId: $caseId, definition: $definition);
	}//end projectAtCaseStart()

	/**
	 * The converted definition of a caseType's published caseModel.
	 *
	 * The drain of in-flight blobs ({@see CasePlanMigrationService}) needs the
	 * same definition the projection hands OpenRegister at case start, so both
	 * read it from here and a case migrated later gets the plan a case
	 * started now would get.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} The definition.
	 *
	 * @throws UnexpectedValueException `no_published_case_model`, or the conversion's refusal by name.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function definitionForCaseType(string $caseTypeId): array {
		$model = $this->findPublishedModel(caseTypeId: $caseTypeId);
		if ($model === null) {
			throw new UnexpectedValueException('no_published_case_model');
		}

		return $this->convertModel(caseModel: $model);
	}//end definitionForCaseType()

	/**
	 * Hand the converted definition to OpenRegister's case layer.
	 *
	 * @param object                                                                       $plans      The resolved CasePlanService.
	 * @param string                                                                       $caseId     The case object uuid.
	 * @param array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} $definition The converted definition.
	 *
	 * @return array{projected: boolean, reason: string} What it did, and why.
	 */
	private function createPlan(object $plans, string $caseId, array $definition): array {
		$registerId = $this->configuredId(key: 'register');
		$schemaId = $this->configuredId(key: 'case_schema');

		// An OpenRegister from before the system verbs would refuse a plan
		// with no identity anyway; say which, instead of logging a refusal
		// that reads like a bad model.
		if (method_exists($plans, 'createPlanAsSystem') === false) {
			$this->logger->warning('CasePlanProjectionService: OpenRegister predates createPlanAsSystem', ['caseId' => $caseId]);
			return ['projected' => false, 'reason' => 'case_layer_lacks_system_verbs'];
		}

		try {
			$plans->createPlanAsSystem(objectUuid: $caseId, registerId: $registerId, schemaId: $schemaId, definition: $definition, app: self::SYSTEM_APP);
		} catch (Throwable $e) {
			$this->logger->error('CasePlanProjectionService: OpenRegister refused the projected plan', ['caseId' => $caseId, 'exception' => $e->getMessage()]);
			return ['projected' => false, 'reason' => $e->getMessage()];
		}

		return ['projected' => true, 'reason' => 'projected'];
	}//end createPlan()

	/**
	 * A configured register or schema id, or null when it is not configured.
	 *
	 * @param string $key The config key.
	 *
	 * @return int|null The id.
	 */
	private function configuredId(string $key): ?int {
		$value = $this->settingsService->getConfigValue(key: $key);
		$id = null;
		if ($value !== '') {
			$id = (int)$value;
		}

		return $id;
	}//end configuredId()

	/**
	 * Read one caseType object.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array<string, mixed>|null The caseType, or null when it cannot be read.
	 */
	private function findCaseType(string $caseTypeId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($objectService === null || $caseTypeId === '' || $register === '' || $schema === '') {
			return null;
		}

		try {
			$caseType = $this->asSystemWithoutUser(
				objectService: $objectService,
				userSession: $this->userSession,
				operation: fn (): mixed => $objectService->find($caseTypeId, register: $register, schema: $schema),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CasePlanProjectionService: caseType lookup failed',
				['caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->hits->firstAsArray(value: [$caseType]);
	}//end findCaseType()

	/**
	 * Find the single published caseModel for a caseType.
	 *
	 * Exactly one published model per caseType; none means an empty plan.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array<string, mixed>|null The model, or null when none is published.
	 */
	private function findPublishedModel(string $caseTypeId): ?array {
		$scope = $this->modelSearchScopeFor(caseTypeId: $caseTypeId);
		if ($scope === []) {
			return null;
		}

		try {
			$found = $scope['objectService']->searchObjects($this->publishedModelQuery(scope: $scope, caseTypeId: $caseTypeId));
		} catch (Throwable $e) {
			$this->logModelLookupFailed(caseTypeId: $caseTypeId, error: $e);
			return null;
		}

		return $this->hits->publishedModelOf(found: $found);
	}//end findPublishedModel()

	/**
	 * Where a caseType's published caseModel is searched; empty for no caseType or a missing store.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array{objectService: object, register: string, schema: string}|array{}
	 */
	private function modelSearchScopeFor(string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		return $this->modelSearchScope();
	}//end modelSearchScopeFor()

	/**
	 * The search for a caseType's published caseModels.
	 *
	 * @param array{objectService: object, register: string, schema: string} $scope Where to search.
	 * @param string $caseTypeId The caseType uuid.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function publishedModelQuery(array $scope, string $caseTypeId): array {
		return [
			'@self' => [
				'register' => (int)$scope['register'],
				'schema' => (int)$scope['schema'],
			],
			'caseType' => $caseTypeId,
			'lifecycleStatus' => 'published',
		];
	}//end publishedModelQuery()

	/**
	 * Log a caseModel lookup that failed.
	 *
	 * @param string $caseTypeId The caseType uuid.
	 * @param Throwable $error Why it failed.
	 *
	 * @return void
	 */
	private function logModelLookupFailed(string $caseTypeId, Throwable $error): void {
		$this->logger->error(
			'CasePlanProjectionService: caseModel lookup failed',
			['caseType' => $caseTypeId, 'exception' => $error->getMessage()]
		);
	}//end logModelLookupFailed()

	/**
	 * Where published caseModels are searched: the store, the register and the model schema; empty when any is missing.
	 *
	 * @return array{objectService: object, register: string, schema: string}|array{}
	 */
	private function modelSearchScope(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$modelSchema = $this->settingsService->getConfigValue(key: 'case_model_schema');
		if ($objectService === null || $register === '' || $modelSchema === '') {
			return [];
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $modelSchema];
	}//end modelSearchScope()

}//end class
