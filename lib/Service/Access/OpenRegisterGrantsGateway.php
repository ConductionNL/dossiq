<?php

/**
 * Dossiq's one reader of OpenRegister's effective grants.
 *
 * 🔴 IT COMPUTES NOTHING, AND THAT IS THE WHOLE CLASS.
 *
 * D22 ruled that access is a property of the object, the object lives in
 * OpenRegister, and the grant is evaluated there. The same argument forbids a
 * second evaluator here: two evaluators are two answers, and the first time
 * they disagree the disagreement is a disclosure. So every method below hands
 * a question to OpenRegister and returns what came back, verbatim. Nothing is
 * merged, defaulted, cached or summarised.
 *
 * WHY IT RESOLVES THROUGH THE CONTAINER RATHER THAN BY CONSTRUCTOR TYPE.
 * Dossiq runs against OpenRegister builds that predate
 * `permission-provenance-and-deny` (openregister#3726). On those,
 * `PermissionHandler::provenanceFor()` does not exist, and a constructor
 * type-hint would fail the whole app at boot instead of one panel. Hence the
 * two guards, the same pair {@see \OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway}
 * uses: the app must be installed, and the resolved service must actually
 * carry the method about to be called.
 *
 * WHY AN ABSENCE IS `null` AND NEVER `[]`. An empty provenance array is a
 * legitimate answer — a schema with no rules reports nothing — and a caller
 * that read "OpenRegister cannot answer" as "OpenRegister granted nothing"
 * would lock every handler out the moment the app was disabled. Null is "no
 * answer", and every caller has to decide what to do about it in the open.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks OpenRegister who may do what on a case, and passes the answer on.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
class OpenRegisterGrantsGateway {

	/**
	 * OpenRegister's RBAC evaluator, resolved by name.
	 *
	 * @var string
	 */
	private const PERMISSION_HANDLER = 'OCA\OpenRegister\Service\Object\PermissionHandler';

	/**
	 * OpenRegister's schema lookup, resolved by name.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * The method openregister#3726 added, and the one this gateway is for.
	 *
	 * @var string
	 */
	private const PROVENANCE_METHOD = 'provenanceFor';

	/**
	 * The verb a case move writes with.
	 *
	 * A transition rewrites the case object, so `update` is the grant a move
	 * needs. Named as a constant because the caller that reads it must not be
	 * free to pick a different verb and get a different answer.
	 *
	 * @var string
	 */
	public const WRITE_ACTION = 'update';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager      Tells whether OpenRegister is installed at all.
	 * @param ContainerInterface $container       Resolves OpenRegister's services by name.
	 * @param SettingsService    $settingsService Holds the configured register and case schema.
	 * @param LoggerInterface    $logger          Records why an answer could not be had.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister's provenance for one case and one caller.
	 *
	 * The return value is OpenRegister's, key for key: per action a record
	 * carrying `granted`, `source`, `rule`, `principal`, `role`, `deny`,
	 * `stagedDeny` and `wouldHaveBeenGrantedBy`. Dossiq adds nothing to it and
	 * takes nothing away, because D-5 says provenance is read and never
	 * summarised: a summary is a second access decision that nobody updates.
	 *
	 * @param string             $caseId  The case uuid.
	 * @param array<int, string> $actions The verbs to ask about.
	 * @param string|null        $userId  The caller, or null for the session user.
	 *
	 * @return array<string, array<string, mixed>>|null OpenRegister's answer, or null
	 *                                                  when it could not be asked.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function provenanceForCase(string $caseId, array $actions, ?string $userId = null): ?array {
		$handler = $this->permissionHandler();
		if ($handler === null) {
			return null;
		}

		$schema = $this->caseSchema();
		if ($schema === null) {
			return null;
		}

		try {
			$provenance = $handler->provenanceFor(
				schema: $schema,
				actions: $actions,
				userId: $userId,
				object: $this->caseObject(caseId: $caseId),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq grants gateway: OpenRegister could not answer the provenance for a case',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return null;
		}

		if (is_array($provenance) === false) {
			return null;
		}

		return $provenance;
	}//end provenanceForCase()

	/**
	 * Whether OpenRegister says, in its own words, that the caller may not write.
	 *
	 * 🔑 THIS READS A BOOLEAN OPENREGISTER WROTE. It does not derive one. The
	 * record is OpenRegister's verdict on one verb; the only thing happening
	 * here is that `granted` is read off it and the absence of an answer is
	 * kept distinct from a "no". A caller that cannot get an answer is told so
	 * by `null`, and decides for itself — which is why this returns a nullable
	 * bool rather than falling either open or closed on dossiq's behalf.
	 *
	 * @param array<string, mixed>|null $record One action's provenance record.
	 *
	 * @return bool|null True when OpenRegister refused, false when it granted,
	 *                   null when there is no record to read.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function refuses(?array $record): ?bool {
		if ($record === null || array_key_exists('granted', $record) === false) {
			return null;
		}

		return ($record['granted'] === false);
	}//end refuses()

	/**
	 * Whether OpenRegister refuses this caller the write a move needs.
	 *
	 * The one question a caller actually has, answered in the one class that
	 * may ask it. It lives here rather than in the caller for two reasons. The
	 * interpretation of OpenRegister's record belongs beside the code that
	 * fetched it, so there is one place to read when the record's shape
	 * changes. And a caller that had to assemble the answer from
	 * `provenanceForCase()` and `refuses()` itself would be doing the reading
	 * OpenRegister already did, one step closer to the second evaluator D-1
	 * forbids.
	 *
	 * 🔑 IT ANSWERS `true` ONLY WHEN OPENREGISTER ITSELF SAID NO. Every other
	 * outcome, including "OpenRegister could not be asked", is `false`.
	 * Reading an absence as a refusal would lock every handler out of every
	 * case on an instance that has not upgraded to openregister#3726, which is
	 * a worse failure than the one this change is about. The authorization
	 * that still runs in that case is OpenRegister's own, at the object API,
	 * which dossiq never bypasses.
	 *
	 * The record is logged verbatim rather than summarised, so the refusal is
	 * traceable to the rule instead of to an empty screen (D-5).
	 *
	 * @param string      $caseId The case uuid.
	 * @param string|null $userId The caller, null when the session decides.
	 *
	 * @return bool True only when OpenRegister refused the write.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function refusesTheWrite(string $caseId, ?string $userId = null): bool {
		$provenance = $this->provenanceForCase(
			caseId: $caseId,
			actions: [self::WRITE_ACTION],
			userId: $userId,
		);

		$record = null;
		if ($provenance !== null && is_array($provenance[self::WRITE_ACTION] ?? null) === true) {
			$record = $provenance[self::WRITE_ACTION];
		}

		if ($this->refuses(record: $record) !== true) {
			return false;
		}

		$this->logger->info(
			'Dossiq grants gateway: OpenRegister refuses this caller the write on a case',
			['caseId' => $caseId, 'action' => self::WRITE_ACTION, 'provenance' => $record],
		);

		return true;
	}//end refusesTheWrite()

	/**
	 * Resolve OpenRegister's RBAC evaluator, or null.
	 *
	 * @return object|null The evaluator when it is there and carries the method.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	private function permissionHandler(): ?object {
		if ($this->appManager->isInstalled('openregister') === false) {
			return null;
		}

		try {
			$handler = $this->container->get(self::PERMISSION_HANDLER);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq grants gateway: OpenRegister PermissionHandler unavailable',
				['exception' => $e->getMessage()],
			);
			return null;
		}

		// An OpenRegister that predates openregister#3726 has the service and
		// not the method. Calling it anyway would be a fatal Error inside a
		// lifecycle provider, which OpenRegister answers with a 502 and a
		// timeline of disabled stages.
		if (method_exists($handler, self::PROVENANCE_METHOD) === false) {
			$this->logger->info(
				'Dossiq grants gateway: this OpenRegister has no provenance reader, so no grant is named'
			);
			return null;
		}

		return $handler;
	}//end permissionHandler()

	/**
	 * The configured case schema, as OpenRegister's own entity.
	 *
	 * @return object|null The schema, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	private function caseSchema(): ?object {
		$slug = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($slug === '') {
			return null;
		}

		try {
			$schema = $this->container->get(self::SCHEMA_MAPPER)->find($slug);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq grants gateway: the case schema could not be resolved',
				['exception' => $e->getMessage(), 'schema' => $slug],
			);
			return null;
		}

		if (is_object($schema) === false) {
			return null;
		}

		return $schema;
	}//end caseSchema()

	/**
	 * The case as OpenRegister's own entity, for an object-level rule.
	 *
	 * Null is a legitimate argument to `provenanceFor()`: it then reports the
	 * schema and register levels only, which is the right answer for a case
	 * that carries no block of its own. So a lookup that fails degrades to the
	 * broader answer rather than to no answer at all.
	 *
	 * ⚠️ THIS IS A SECOND READ OF A CASE OPENREGISTER HAS USUALLY JUST READ,
	 * and it is paid only where it buys something. It is reached after both
	 * guards in `permissionHandler()`, so an OpenRegister without the
	 * provenance reader never gets here at all, which is most instances today.
	 * The object level cannot be skipped on the ones that do: an object block
	 * is exactly where a share on one case lives, and reporting the schema
	 * answer as though it were the whole answer would name the wrong rule. If
	 * this shows up in a profile, the fix is an OpenRegister that carries the
	 * permitted actions on the object it already read (its tasks 7.1 to 7.3),
	 * not a copy of the grant kept here.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return object|null The object entity, or null.
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	private function caseObject(string $caseId): ?object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$object = $objectService->find($caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq grants gateway: the case could not be read for its own authorization block',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return null;
		}

		if (is_object($object) === false) {
			return null;
		}

		return $object;
	}//end caseObject()
}//end class
