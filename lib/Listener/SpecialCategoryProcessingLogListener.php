<?php

/**
 * The processing log records the special categories a case processes.
 *
 * A case's `gdprClassification` names the special categories of personal data
 * (GDPR articles 9 and 10) it processes. When a case is created with them, or
 * a save changes them, one processing-log row says which categories, on which
 * case, by whom. A save that leaves them as they were writes nothing, so a
 * rematerialisation or a status move does not repeat the row.
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
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\TenantAuditTrailServiceInterface;
use OCA\Dossiq\Listener\Support\NamesTheActor;
use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Intake\SpecialCategoryClassification;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the processing-log row for a case's special categories.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
class SpecialCategoryProcessingLogListener implements IEventListener {
	use NamesTheActor;

	use SavedObjectPayload;

	/**
	 * The schema slug of a case.
	 */
	public const CASE_SCHEMA = 'case';

	/**
	 * The processing-log action of the row.
	 */
	public const ACTION = 'case_special_categories_recorded';

	/**
	 * Constructor.
	 *
	 * @param TenantAuditTrailServiceInterface  $auditTrail   The processing log.
	 * @param ObjectSchemaSlugResolverInterface $slugResolver Tells a case from other objects.
	 * @param IUserSession                      $userSession  Who made the save.
	 * @param LoggerInterface                   $logger       For a row that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TenantAuditTrailServiceInterface $auditTrail,
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the row when a case gains or changes its special categories.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$after = $this->savedObject(event: $event);
		if ($after === null || $this->slugResolver->resolveFromPayload(payload: $after) !== self::CASE_SCHEMA) {
			return;
		}

		$categories = self::categories(value: ($after[SpecialCategoryClassification::FIELD] ?? null));
		$previous = [];
		if ($event instanceof ObjectUpdatedEvent) {
			$before = $this->previousObject(event: $event);
			$previous = self::categories(value: (($before ?? [])[SpecialCategoryClassification::FIELD] ?? null));
		}

		if ($categories === $previous) {
			return;
		}

		$caseId = trim((string) ($after['id'] ?? ''));
		try {
			$this->auditTrail->emit(
				[
					'action' => self::ACTION,
					'actor' => $this->actor(),
					'resource' => 'case:'.$caseId,
					'bio' => ['caseId' => $caseId, 'categories' => $categories, 'previous' => $previous],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the special categories of a case could not be written to the processing log',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The categories of a value, trimmed and sorted, so a reordering is no change.
	 *
	 * @param mixed $value A list of categories, one category, or nothing.
	 *
	 * @return list<string>
	 */
	private static function categories(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$categories = [];
		foreach ($value as $category) {
			if (is_string($category) === true && trim($category) !== '') {
				$categories[] = trim($category);
			}
		}

		$categories = array_values(array_unique($categories));
		sort($categories);

		return $categories;
	}//end categories()
}//end class
