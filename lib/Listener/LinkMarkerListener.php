<?php

/**
 * Dossiq link marker listener.
 *
 * Applies the marker rules in app config `link_marker_rules` after an object is
 * created or updated. For every rule whose source schema the saved object is
 * in, the target it references is read, the rule evaluated over both, and the
 * marker written to the target when it changed.
 *
 * A rule that fails is logged and skipped: the write that triggered it has
 * already happened and is not the marker's to undo.
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Relation\LinkMarkerRule;
use OCA\Dossiq\Service\Relation\LinkMarkerRuleReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives configured markers on referenced objects.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
 */
class LinkMarkerListener implements IEventListener {

	use SearchesObjects;

	/**
	 * The app config key that holds the rules.
	 *
	 * @var string
	 */
	public const RULES_KEY = 'link_marker_rules';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, schema keys, rules and the object service.
	 * @param LoggerInterface $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply every rule whose source schema the saved object is in.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-marker-rule-derives-a-flag-on-the-referenced-object-req-lcr-4
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$source = $this->objectOf(event: $event);
		if ($source === null) {
			return;
		}

		try {
			$rules = (new LinkMarkerRuleReader())->fromJson(json: $this->settingsService->getConfigValue(key: self::RULES_KEY));
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the link marker rules do not read: '.$e->getMessage());
			return;
		}

		foreach ($rules as $rule) {
			try {
				$this->apply(rule: $rule, source: $source);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: link marker rule skipped: '.$e->getMessage(),
					['marker' => $rule->marker]
				);
			}
		}
	}//end handle()

	/**
	 * Apply one rule to a saved object.
	 *
	 * @param LinkMarkerRule       $rule   The rule.
	 * @param array<string, mixed> $source The saved object.
	 *
	 * @return void
	 */
	private function apply(LinkMarkerRule $rule, array $source): void {
		$sourceSchema = $this->settingsService->getConfigValue(key: $rule->source);
		if ($sourceSchema === '' || $this->isInSchema(object: $source, schema: $sourceSchema) === false) {
			return;
		}

		$targetId = $this->referenceOf(source: $source, field: $rule->field);
		if ($targetId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register      = $this->settingsService->getConfigValue(key: 'register');
		$targetSchema  = $this->settingsService->getConfigValue(key: $rule->target);
		if ($objectService === null || $register === '' || $targetSchema === '') {
			return;
		}

		$target = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $targetSchema,
			id: $targetId
		);
		if ($target === null) {
			return;
		}

		$marker = $rule->holds(source: $source, target: $target);
		if (($target[$rule->marker] ?? null) === $marker) {
			return;
		}

		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $targetSchema,
			id: $targetId,
			changes: [$rule->marker => $marker]
		);
	}//end apply()

	/**
	 * The uuid a source field references, or '' when it holds none.
	 *
	 * @param array<string, mixed> $source The saved object.
	 * @param string               $field  The reference field.
	 *
	 * @return string The uuid.
	 */
	private function referenceOf(array $source, string $field): string {
		$value = ($source[$field] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceOf()

	/**
	 * Whether an object is in a schema, by id or by slug.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $schema The configured schema id or slug.
	 *
	 * @return bool True when it is.
	 */
	private function isInSchema(array $object, string $schema): bool {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($self['schema'] ?? null), ($self['schemaSlug'] ?? null), ($object['schema'] ?? null)] as $candidate) {
			if (is_scalar($candidate) === false) {
				continue;
			}

			$candidate = (string)$candidate;
			if ($candidate === $schema || str_ends_with($candidate, '/'.$schema) === true) {
				return true;
			}
		}

		return false;
	}//end isInSchema()

	/**
	 * The saved object of an event as an array.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function objectOf(Event $event): ?array {
		foreach (['getNewObject', 'getObject'] as $method) {
			if (method_exists($event, $method) === false) {
				continue;
			}

			$value = $event->{$method}();
			if (is_array($value) === true) {
				return $value;
			}

			if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
				$serialised = $value->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}
		}

		return null;
	}//end objectOf()
}//end class
