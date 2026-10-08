<?php

/**
 * Dossiq case delete guard.
 *
 * REQ-CM-35: a case is deleted only when nothing holds it, and when something
 * does, the refusal names every rule that holds rather than the first one it
 * hit. Two guards existed before this one and both were narrow:
 * {@see BezwaarLegalHoldListener} places an OpenRegister legal hold while an
 * Awb bezwaar or beroep runs, and {@see BeschikkingImmutabilityListener}
 * refuses a delete on a signed beschikking. Neither looked at the case itself,
 * so a case with a running beslistermijn, with sub-cases hanging off it, or
 * inside its Archiefwet retention period could be deleted and nothing said so.
 *
 * It subscribes to OpenRegister's PRE-persist, stoppable `ObjectDeletingEvent`.
 * The post-persist pair cannot be used: `ObjectDeletedEvent` is dispatched
 * after the row is gone, with no surrounding transaction, so a listener there
 * cannot stop the delete it objects to (ADR-078).
 *
 * Every rule is one bounded read and none of them writes (ADR-058). The legal
 * hold is READ here and set elsewhere: `BezwaarLegalHoldListener` keeps that
 * job, and this guard only reports the hold it placed.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Exception\CaseHeldException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse the delete of a case that a term, a sub-case, a hold or retention keeps.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */
class CaseDeleteGuardListener implements IEventListener {

	use SearchesObjects;

	/**
	 * The app-config key naming the schema this guard watches.
	 *
	 * The binding is by schema and nothing else. Nextcloud's registration API
	 * takes an event class and a listener class, so the registrar cannot pass
	 * a schema; it names this constant instead, and the listener returns for
	 * every other schema on the instance (ADR-078, the self-filter half).
	 *
	 * @var string
	 */
	public const GUARDED_SCHEMA_CONFIG_KEY = 'case_schema';

	/**
	 * The deadlineInstance statuses that mean a term is still running.
	 *
	 * `completed`, `exceeded` and `withdrawn` are the terms that have stopped.
	 * An exceeded term is deliberately NOT a hold: the clock ran out, which is
	 * a dwangsom question, not a reason to keep the file.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TERM_STATUSES = ['lopend', 'verlengd', 'paused'];

	/**
	 * How many terms of one case this guard reads.
	 *
	 * A case carries a handful of statutory terms, one per Awb stage, so this
	 * page holds every term a real case has. It is a cap and not a filter:
	 * the statuses are compared in PHP because a single query with a list of
	 * statuses depends on OpenRegister's filter grammar for array values, and
	 * a grammar that drops the key answers with the whole register rather than
	 * with an error.
	 *
	 * @var int
	 */
	private const TERM_PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus app config.
	 * @param IL10N $l10n Translation service, for the refusal sentence.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case delete and refuse it while anything holds.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false) {
			return;
		}

		$object = $event->getObject();
		$payload = $this->payload(object: $object);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$caseId = (string)($object->getUuid() ?? '');
		$rules = $this->rulesThatHold(caseId: $caseId, payload: $payload, object: $object);
		if ($rules === []) {
			return;
		}

		$refusal = new CaseHeldException(rules: $rules, message: $this->sentence(rules: $rules));

		$event->setErrors($refusal->toResponseBody());
		$event->stopPropagation();

		$this->logger->info(
			'Dossiq: refused the delete of a held case (REQ-CM-35)',
			['uuid' => $caseId, 'blockedBy' => $rules]
		);
	}//end handle()

	/**
	 * Which rules hold this case, in the order a sentence names them.
	 *
	 * Every rule runs: the point of the requirement is that one refusal says
	 * everything, so a handler clears the case in one pass instead of meeting
	 * the next reason after fixing the first.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $payload The case payload as it stands.
	 * @param ObjectEntity $object The entity carried by the event.
	 *
	 * @return array<int, string> The rule slugs that hold, possibly empty.
	 */
	private function rulesThatHold(string $caseId, array $payload, ObjectEntity $object): array {
		$rules = [];

		if ($caseId !== '' && $this->hasOpenTerm(caseId: $caseId) === true) {
			$rules[] = CaseHeldException::RULE_OPEN_TERM;
		}

		if ($caseId !== '' && $this->hasSubCases(caseId: $caseId) === true) {
			$rules[] = CaseHeldException::RULE_HAS_SUBCASES;
		}

		if ($this->hasLegalHold(object: $object) === true) {
			$rules[] = CaseHeldException::RULE_LEGAL_HOLD;
		}

		if ($this->isInRetention(payload: $payload) === true) {
			$rules[] = CaseHeldException::RULE_IN_RETENTION;
		}

		return $rules;
	}//end rulesThatHold()

	/**
	 * Whether a statutory term of this case is still running.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return bool True when at least one deadlineInstance is open.
	 */
	private function hasOpenTerm(string $caseId): bool {
		$rows = $this->rowsFor(
			schemaConfigKey: 'termijn_instance_schema',
			filters: ['case' => $caseId, '_limit' => self::TERM_PAGE_SIZE]
		);

		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if (in_array($status, self::OPEN_TERM_STATUSES, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasOpenTerm()

	/**
	 * Whether another case names this one as its parent.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return bool True when the case has at least one sub-case.
	 */
	private function hasSubCases(string $caseId): bool {
		$rows = $this->rowsFor(
			schemaConfigKey: self::GUARDED_SCHEMA_CONFIG_KEY,
			filters: ['parentCase' => $caseId, '_limit' => 1]
		);

		return $rows !== [];
	}//end hasSubCases()

	/**
	 * Whether OpenRegister carries an active legal hold on the case.
	 *
	 * `ObjectEntity::hasActiveLegalHold()` is OpenRegister's SINGLE definition
	 * of held, so this guard asks it rather than reading the retention array
	 * and restating the rule. A RELEASED hold leaves the `legalHold` key in
	 * place with `active: false`, which is why "the key exists" is not the
	 * question and a local copy of the predicate gets it wrong.
	 *
	 * @param ObjectEntity $object The entity carried by the event.
	 *
	 * @return bool True when a hold is active.
	 */
	private function hasLegalHold(ObjectEntity $object): bool {
		return ($object->hasActiveLegalHold() === true);
	}//end hasLegalHold()

	/**
	 * Whether the case is closed and its retention period has not ended.
	 *
	 * An OPEN case is never held by retention: the period counts from the
	 * close, so a case still in hand has no disposal date to be inside of.
	 * The date is read from OpenRegister's retention decision first, which is
	 * the one place any app can ask an object what happens to it and when,
	 * and from the case's own `archiveActionDate` when the decision has not
	 * been rendered onto the entity. A closed case with no date at all is NOT
	 * held: this guard reports retention, it does not invent one.
	 *
	 * @param array<string, mixed> $payload The serialised case payload.
	 *
	 * @return bool True when the case is closed and still inside its period.
	 */
	private function isInRetention(array $payload): bool {
		if (trim((string)($payload['endDate'] ?? '')) === '') {
			return false;
		}

		$disposal = $this->disposalDate(payload: $payload);
		if ($disposal === null) {
			return false;
		}

		return ($disposal > new DateTimeImmutable('today'));
	}//end isInRetention()

	/**
	 * The date this case may be disposed of, or null when none is known.
	 *
	 * @param array<string, mixed> $payload The serialised case payload.
	 *
	 * @return DateTimeImmutable|null The disposal date at midnight, or null.
	 */
	private function disposalDate(array $payload): ?DateTimeImmutable {
		$self = ($payload['@self'] ?? []);
		$decision = [];
		if (is_array($self) === true && is_array(($self['_retention'] ?? null)) === true) {
			$decision = $self['_retention'];
		}

		$raw = trim((string)($decision['disposalDate'] ?? ($payload['archiveActionDate'] ?? '')));
		if ($raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($raw, 0, 10));
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: case delete guard could not read a disposal date: ' . $e->getMessage()
			);
			return null;
		}
	}//end disposalDate()

	/**
	 * The refusal, naming every rule that holds the case.
	 *
	 * Each reason is a whole sentence rather than a clause slotted into one,
	 * because a translator cannot put a fragment in the right case, order or
	 * gender without the sentence around it, and Dutch puts all three
	 * somewhere else than English does.
	 *
	 * @param array<int, string> $rules The rule slugs that hold.
	 *
	 * @return string The refusal, translated.
	 */
	private function sentence(array $rules): string {
		$sentences = [$this->l10n->t('You cannot delete this case yet.')];
		foreach ($rules as $rule) {
			$sentences[] = $this->reason(rule: $rule);
		}

		$sentences[] = $this->l10n->t('Resolve this first, then delete the case.');

		return implode(' ', $sentences);
	}//end sentence()

	/**
	 * The sentence one rule contributes to the refusal.
	 *
	 * @param string $rule One of {@see CaseHeldException::RULES}.
	 *
	 * @return string The translated sentence.
	 */
	private function reason(string $rule): string {
		return match ($rule) {
			CaseHeldException::RULE_OPEN_TERM => $this->l10n->t('A statutory term on this case is still running.'),
			CaseHeldException::RULE_HAS_SUBCASES => $this->l10n->t('This case still has sub-cases.'),
			CaseHeldException::RULE_LEGAL_HOLD => $this->l10n->t('A legal hold is on this case.'),
			default => $this->l10n->t('The retention period of this case has not ended.'),
		};
	}//end reason()

	/**
	 * Read a bounded page of rows from one configured schema.
	 *
	 * Never throws: a guard that cannot read its store must not turn a delete
	 * into a 500. It logs and reports no hold, which is the same answer the
	 * instance gave before this guard existed.
	 *
	 * @param string $schemaConfigKey The app-config key naming the schema.
	 * @param array<string, mixed> $filters Object-field filters plus `_limit`.
	 *
	 * @return array<int, array<string, mixed>> The rows, or an empty list.
	 */
	private function rowsFor(string $schemaConfigKey, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: case delete guard could not read ' . $schemaConfigKey,
				['error' => $e->getMessage()]
			);
			return [];
		}
	}//end rowsFor()

	/**
	 * Read the entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $object The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $object): ?array {
		try {
			return $object->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: case delete guard could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the guarded `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue(self::GUARDED_SCHEMA_CONFIG_KEY);
		if ($expected === '') {
			return false;
		}

		$self = ($object['@self'] ?? []);
		$candidate = '';
		if (is_array($self) === true && is_scalar(($self['schema'] ?? null)) === true) {
			$candidate = (string)$self['schema'];
		}

		if ($candidate === '' && is_scalar(($object['schema'] ?? null)) === true) {
			$candidate = (string)$object['schema'];
		}

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isCaseSchema()
}//end class
