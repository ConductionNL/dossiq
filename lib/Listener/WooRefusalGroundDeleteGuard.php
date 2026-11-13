<?php

/**
 * Dossiq Woo refusal ground delete guard.
 *
 * REQ-WRG-002: a Woo refusal ground is never deleted while an assessment or a
 * decision cites it. It is retired instead, and a retired ground stays
 * readable for whatever cites it. The guard subscribes to OpenRegister's
 * pre-persist, stoppable `ObjectDeletingEvent` (ADR-078) and returns for every
 * other schema on the instance.
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
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses the delete of a cited Woo refusal ground.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
 */
class WooRefusalGroundDeleteGuard implements IEventListener {

	use SearchesObjects;

	/**
	 * The app-config key naming the guarded schema.
	 */
	public const GUARDED_SCHEMA_CONFIG_KEY = 'woo_refusal_ground_schema';

	/**
	 * The guarded schema's slug, matched when the config key is not set yet.
	 */
	public const GUARDED_SCHEMA_SLUG = 'wooRefusalGround';

	/**
	 * The schemas whose rows cite grounds in `weigeringsgronden`, by config key.
	 */
	private const CITING_SCHEMA_CONFIG_KEYS = ['woo_assessment_schema', 'decision_schema'];

	/**
	 * How many citing rows one check reads per schema.
	 */
	private const PAGE_SIZE = 5000;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Bridge to OpenRegister plus app config.
	 * @param IL10N           $l10n            Translation service, for the refusal sentence.
	 * @param LoggerInterface $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a ground's delete while anything cites it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-one-hierarchical-list-of-grounds-in-dossiqs-register-req-wrg-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false) {
			return;
		}

		$code = $this->guardedCode(event: $event);
		if ($code === '') {
			return;
		}

		$this->holdIfCited(event: $event, code: $code);
	}//end handle()

	/**
	 * The code of the refusal ground being deleted, or '' when the delete is not one this guard holds.
	 *
	 * @param ObjectDeletingEvent $event The delete.
	 *
	 * @return string
	 */
	private function guardedCode(ObjectDeletingEvent $event): string {
		try {
			$payload = $event->getObject()->jsonSerialize();
		} catch (Throwable $e) {
			return '';
		}

		if ($this->isGuardedSchema(object: $payload) === false) {
			return '';
		}

		return trim((string)($payload['code'] ?? ''));
	}//end guardedCode()

	/**
	 * Refuse the delete when the ground is cited, or when what cites it cannot be checked.
	 *
	 * @param ObjectDeletingEvent $event The delete.
	 * @param string              $code  The ground's code.
	 *
	 * @return void
	 */
	private function holdIfCited(ObjectDeletingEvent $event, string $code): void {
		$citations = $this->citations(code: $code);
		if ($citations === 0) {
			return;
		}

		$event->setErrors(['error' => 'woo-refusal-ground-cited', 'message' => $this->refusalSentence(code: $code, citations: $citations)]);
		$event->stopPropagation();

		$this->logger->info(
			'Dossiq: refused the delete of a cited Woo refusal ground (REQ-WRG-002)',
			['code' => $code, 'citations' => $citations]
		);
	}//end holdIfCited()

	/**
	 * Why the delete is refused: the ground is cited, or what cites it could not be checked.
	 *
	 * @param string $code      The ground's code.
	 * @param int    $citations The citing rows, or -1.
	 *
	 * @return string
	 */
	private function refusalSentence(string $code, int $citations): string {
		$sentence = $this->l10n->t(
			'Ground %1$s is cited on an assessment or a decision, so it cannot be deleted. Retire it instead.',
			[$code]
		);
		if ($citations < 0) {
			$sentence = $this->l10n->t(
				'Ground %1$s cannot be deleted now, because what cites it could not be checked. Try again later.',
				[$code]
			);
		}

		return $sentence;
	}//end refusalSentence()

	/**
	 * How many rows cite the code, or -1 when that cannot be read.
	 *
	 * A failed read refuses the delete: deleting a ground that may be cited
	 * is the one outcome the requirement rules out.
	 *
	 * @param string $code The ground's code.
	 *
	 * @return int The number of citing rows, or -1.
	 */
	private function citations(string $code): int {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '') {
			return -1;
		}

		$count = new CitationCount();
		$count->objectService = $objectService;
		$count->register = $register;
		$count->code = $code;

		$total = 0;
		foreach (self::CITING_SCHEMA_CONFIG_KEYS as $key) {
			$found = $this->citationsUnder(count: $count, key: $key);
			if ($found < 0) {
				return -1;
			}

			$total += $found;
		}

		return $total;
	}//end citations()

	/**
	 * How many rows of one citing schema cite the code, 0 when the schema is not configured, -1 when it cannot be read.
	 *
	 * @param CitationCount $count The count.
	 * @param string        $key   The setting that names the citing schema.
	 *
	 * @return int
	 */
	private function citationsUnder(CitationCount $count, string $key): int {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			return 0;
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $count->objectService,
				register: $count->register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read what cites a Woo refusal ground',
				['code' => $count->code, 'schema' => $key, 'error' => $e->getMessage()]
			);
			return -1;
		}

		return count(array_filter($rows, static fn (array $row): bool => in_array($count->code, (array)($row['weigeringsgronden'] ?? []), true)));
	}//end citationsUnder()

	/**
	 * Whether the payload belongs to the guarded schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True for a Woo refusal ground.
	 */
	private function isGuardedSchema(array $object): bool {
		$candidate = $this->selfSchemaOf(object: $object);
		if ($candidate === '') {
			return false;
		}

		$expected = [self::GUARDED_SCHEMA_SLUG, $this->settingsService->getConfigValue(self::GUARDED_SCHEMA_CONFIG_KEY)];
		foreach ($expected as $name) {
			if ($name !== '' && ($candidate === $name || str_ends_with($candidate, '/' . $name) === true)) {
				return true;
			}
		}

		return false;
	}//end isGuardedSchema()

	/**
	 * The schema the payload's `@self` names, or ''.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return string
	 */
	private function selfSchemaOf(array $object): string {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === true && is_scalar(($self['schema'] ?? null)) === true) {
			return (string)$self['schema'];
		}

		return '';
	}//end selfSchemaOf()
}//end class
