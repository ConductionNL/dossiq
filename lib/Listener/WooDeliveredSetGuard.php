<?php

/**
 * Dossiq Woo delivered set guard
 *
 * A frozen delivered set and the assessments it names refuse change
 * (woo-delivered-set-is-a-record REQ-WDS-002). Listens to OpenRegister's
 * ObjectUpdatingEvent and ObjectDeletingEvent. The one change a frozen set
 * accepts is its first `withdrawnAt` stamp, which the publication withdraw
 * writes. When the frozen sets of a case cannot be read, a write to an
 * assessment of that case is refused: an unreadable record is no proof that
 * nothing was delivered.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses writes to a frozen set and to the assessments it names.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
 */
class WooDeliveredSetGuard implements IEventListener {

	/**
	 * The assessment schema's slug, used when the config key is not set yet.
	 */
	private const ASSESSMENT_SLUG = 'wooDocumentAssessment';

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settings The configured schemas.
	 * @param WooDeliveredSetWriter $sets     Reads the frozen sets of a case.
	 * @param IL10N                 $l10n     The refusal sentence.
	 * @param LoggerInterface       $logger   Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly WooDeliveredSetWriter $sets,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when a frozen set covers the object.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false && $event instanceof ObjectDeletingEvent === false) {
			return;
		}

		[$old, $new] = $this->objectsOf(event: $event);
		$current = ($old ?? $new);
		if ($current === null) {
			return;
		}

		$deliveredAt = null;
		if ($this->isSchema(object: $current, configKey: WooDeliveredSetWriter::CONFIG_KEY, slug: WooDeliveredSetWriter::SCHEMA_SLUG) === true) {
			$deliveredAt = $this->frozenSetRefusal(old: ($old ?? []), new: $new);
		} else if ($this->isSchema(object: $current, configKey: 'woo_assessment_schema', slug: self::ASSESSMENT_SLUG) === true) {
			$deliveredAt = $this->deliveredAssessmentRefusal(assessment: $current);
		}

		if ($deliveredAt === null) {
			return;
		}

		$sentence = $this->l10n->t(
			'This was delivered for publication on %1$s, so it cannot be changed. Publish again to deliver a new set.',
			[substr($deliveredAt, 0, 10)]
		);
		if ($deliveredAt === '') {
			$sentence = $this->l10n->t('Whether this was delivered for publication could not be checked, so it cannot be changed now. Try again later.');
		}

		$event->setErrors(['error' => 'woo-delivered-set-frozen', 'message' => $sentence]);
		$event->stopPropagation();
		$this->logger->info('Dossiq: refused a write to a delivered Woo set or its assessments (REQ-WDS-002)');
	}//end handle()

	/**
	 * The stored object and, for an update, the new one.
	 *
	 * @param ObjectUpdatingEvent|ObjectDeletingEvent $event The event.
	 *
	 * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
	 */
	private function objectsOf(ObjectUpdatingEvent|ObjectDeletingEvent $event): array {
		try {
			if ($event instanceof ObjectDeletingEvent) {
				return [$event->getObject()->jsonSerialize(), null];
			}

			return [$event->getOldObject()?->jsonSerialize(), $event->getNewObject()->jsonSerialize()];
		} catch (Throwable $e) {
			return [null, null];
		}
	}//end objectsOf()

	/**
	 * When a frozen set refuses this write: its delivery date, or null when the write may pass.
	 *
	 * @param array<string, mixed>      $old The stored set.
	 * @param array<string, mixed>|null $new The new set, or null for a delete.
	 *
	 * @return string|null
	 */
	private function frozenSetRefusal(array $old, ?array $new): ?string {
		if ((string)($old['status'] ?? '') !== WooDeliveredSetWriter::STATUS_FROZEN) {
			return null;
		}

		if ($new !== null && $this->isOnlyTheWithdrawStamp(old: $old, new: $new) === true) {
			return null;
		}

		return (string)($old['deliveredAt'] ?? 'an earlier date');
	}//end frozenSetRefusal()

	/**
	 * Whether an update only adds the first `withdrawnAt`.
	 *
	 * @param array<string, mixed> $old The stored set.
	 * @param array<string, mixed> $new The new set.
	 *
	 * @return bool
	 */
	private function isOnlyTheWithdrawStamp(array $old, array $new): bool {
		if (empty($old['withdrawnAt']) === false || empty($new['withdrawnAt']) === true) {
			return false;
		}

		foreach (['case', 'decision', 'status', 'deliveredAt', 'deliveredBy', 'supersedes', 'publication', 'items', 'setHash'] as $key) {
			if (json_encode($old[$key] ?? null) !== json_encode($new[$key] ?? null)) {
				return false;
			}
		}

		return true;
	}//end isOnlyTheWithdrawStamp()

	/**
	 * When an assessment is named by a frozen set: that set's delivery date, '' when unreadable, or null.
	 *
	 * @param array<string, mixed> $assessment The stored assessment.
	 *
	 * @return string|null
	 */
	private function deliveredAssessmentRefusal(array $assessment): ?string {
		$caseId = (string)($assessment['caseRef'] ?? '');
		$id = (string)($assessment['id'] ?? ($assessment['uuid'] ?? (($assessment['@self'] ?? [])['id'] ?? '')));
		if ($caseId === '' || $id === '') {
			return null;
		}

		try {
			$sets = $this->sets->frozenSets(caseId: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the delivered Woo sets of a case could not be read', ['case' => $caseId, 'error' => $e->getMessage()]);
			return '';
		}

		foreach ($sets as $set) {
			if (in_array($id, array_column((array)($set['items'] ?? []), 'assessment'), true) === true) {
				return (string)($set['deliveredAt'] ?? 'an earlier date');
			}
		}

		return null;
	}//end deliveredAssessmentRefusal()

	/**
	 * Whether an object's `@self.schema` is the slug or the configured schema.
	 *
	 * @param array<string, mixed> $object    The serialised object.
	 * @param string               $configKey The config key.
	 * @param string               $slug      The slug.
	 *
	 * @return bool
	 */
	private function isSchema(array $object, string $configKey, string $slug): bool {
		$self = ($object['@self'] ?? []);
		$candidate = '';
		if (is_array($self) === true && is_scalar(($self['schema'] ?? null)) === true) {
			$candidate = (string)$self['schema'];
		}

		if ($candidate === '') {
			return false;
		}

		foreach ([$slug, (string)$this->settings->getConfigValue($configKey)] as $name) {
			if ($name !== '' && ($candidate === $name || str_ends_with($candidate, '/' . $name) === true)) {
				return true;
			}
		}

		return false;
	}//end isSchema()
}//end class
