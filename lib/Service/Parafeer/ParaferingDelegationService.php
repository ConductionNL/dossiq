<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Ask the decision app to hold a parafeerroute as an approval route.
 *
 * Routing a document past a sequence of officials for approval is governance,
 * and governance is the decision app's. A parafeerroute IS a sign-off route:
 * ordered steps, each naming an actor and a stage type, some mandatory.
 *
 * 🔴 THE COMMAND TRAVELS AS A TYPED EVENT, not over REST. ADR-041 says so and
 * gate-27 enforces it, and there is a harder reason besides: the decision app's
 * route controller refuses a request with no signed-in user, and a migration
 * running under `occ upgrade` has none.
 *
 * The shape is {@see \OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService}'s,
 * deliberately: same class_exists guard across both namespace spellings, same
 * positional construction through a class-string, same fail-closed reading of
 * the result slots.
 *
 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
 */
class ParaferingDelegationService {

	/**
	 * Every spelling of the route command event FQN, newest first.
	 *
	 * TWO SPELLINGS because the decision app renamed its namespace from
	 * OCA\Decidesk to OCA\Decidiq with no compatibility alias, and a constant
	 * naming only one reports "not installed" on an instance where it is.
	 *
	 * @var array<int, string>
	 */
	private const APPROVAL_ROUTE_REQUESTED_EVENTS = [
		'\\OCA\\Decidiq\\Event\\ApprovalRouteRequestedEvent',
		'\\OCA\\Decidesk\\Event\\ApprovalRouteRequestedEvent',
	];

	/**
	 * This app's id AS THE DECISION APP KNOWS IT.
	 *
	 * FROZEN. It is half of the key the other side resolves a repeat command on,
	 * so changing it does not rename a mapping — it orphans every route already
	 * held and mints a duplicate set on the next run.
	 *
	 * @var string
	 */
	private const SOURCE_APP = 'dossiq';

	/**
	 * Local step fields that map onto an approval-route step unchanged.
	 *
	 * `actorType` is deliberately NOT here: the two apps speak different
	 * actor vocabularies, so it is translated in mapStep() instead of copied.
	 *
	 * @var array<int, string>
	 */
	private const STEP_FIELDS = ['order', 'actor', 'mandatory', 'label'];

	/**
	 * Local parafeerroute step types, mapped to the approval-route vocabulary.
	 *
	 * `advice` is the parafeerroute schema's own enum spelling
	 * (lib/Settings/dossiq_register.json); `advies` is kept for rows written
	 * before that enum settled. A step type that is not in this map travels as
	 * `endorsement`, which is what an unrecognised signing step is: somebody
	 * has to sign, and no stronger claim is made about what their signature
	 * means.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_TYPES = [
		'advice' => 'advisory',
		'advies' => 'advisory',
		'parafering' => 'endorsement',
		'accordering' => 'decisive',
	];

	/**
	 * Local actorType values, mapped to the approval-route vocabulary.
	 *
	 * The decision app's ApprovalRoute step schema accepts ONLY
	 * `person` | `body` | `role`, and its store REFUSES the whole route on an
	 * unknown value — which is how every shipped demo route with a `group`
	 * actor came back "not handled" on a fresh install. A Nextcloud group,
	 * like a role, is resolved by the consuming context at completion time
	 * (the assignee gate checks membership), so `group` travels as `role`.
	 * Values already in the decision app's vocabulary pass through unchanged.
	 *
	 * @var array<string, string>
	 */
	private const ACTOR_TYPES = [
		'user' => 'person',
		'group' => 'role',
		'role' => 'role',
		'person' => 'person',
		'body' => 'body',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Nextcloud typed event dispatcher.
	 * @param LoggerInterface  $logger          Logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the decision app is installed and exposes the command seam.
	 *
	 * @return boolean True when a command can be dispatched.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function isAvailable(): bool {
		return ($this->resolveEventClass() !== null);

	}//end isAvailable()

	/**
	 * Cause an approval route for this parafeerroute to exist, and return its id.
	 *
	 * Safe to call repeatedly: the other side resolves on
	 * (sourceApp, externalReference) before writing.
	 *
	 * @param array<string, mixed> $route         A local parafeerroute row.
	 * @param string               $actorId       The acting Nextcloud UID.
	 * @param string               $subject       Optional voorstel to start travelling the route now.
	 * @param string               $subjectSchema Schema slug of that subject.
	 *
	 * @return string The approval-route id.
	 *
	 * @throws RuntimeException When the decision app is absent or refused.
	 *
	 * @spec openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md
	 */
	public function holdRoute(
		array $route,
		string $actorId = '',
		string $subject = '',
		string $subjectSchema = '',
	): string {
		$eventClass = $this->resolveEventClass();
		if ($eventClass === null) {
			throw new RuntimeException(
				'Route service unavailable: the decision app is not installed, so no approval route can be held.'
			);
		}

		$reference = $this->referenceOf(route: $route);
		if ($reference === '') {
			throw new RuntimeException('A parafeerroute needs an id before it can be held as an approval route');
		}

		$name = trim((string)($route['name'] ?? ''));
		if ($name === '') {
			throw new RuntimeException('A parafeerroute needs a name before it can be held as an approval route');
		}

		$steps = $this->stepsOf(route: $route);
		if ($steps === []) {
			throw new RuntimeException('A parafeerroute with no steps has nothing to travel');
		}

		try {
			// Positional ctor args (decision-app contract): sourceApp,
			// externalReference, name, steps, subjectType, description,
			// isDefault, subject, subjectSchema, actorId, correlationId.
			$event = new $eventClass(
				self::SOURCE_APP,
				$reference,
				$name,
				$steps,
				trim((string)($route['proposalType'] ?? '')),
				trim((string)($route['description'] ?? '')),
				(bool)($route['isDefault'] ?? false),
				$subject,
				$subjectSchema,
				$actorId,
				$reference
			);

			$this->eventDispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq parafering: ApprovalRouteRequestedEvent dispatch failed',
				['externalReference' => $reference, 'error' => $e->getMessage()]
			);
			throw new RuntimeException('Route service error: ' . $e->getMessage(), 0, $e);
		}//end try

		$handled = (bool)$event->isHandled();
		$routeId = (string)$event->getRouteId();
		if ($handled === false || $routeId === '') {
			$this->logger->error(
				'Dossiq parafering: the decision app did not handle the route command; failing closed',
				['externalReference' => $reference, 'handled' => $handled]
			);
			throw new RuntimeException(
				'Route service unavailable: the decision app did not handle the approval-route command.'
			);
		}

		$this->logger->info(
			'Dossiq parafering: approval route resolved',
			[
				'externalReference' => $reference,
				'approvalRouteId' => $routeId,
				'created' => $event->isCreated(),
			]
		);

		return $routeId;

	}//end holdRoute()

	/**
	 * The local route's own id, which becomes the external reference.
	 *
	 * @param array<string, mixed> $route The route row.
	 *
	 * @return string The id, or an empty string.
	 */
	private function referenceOf(array $route): string {
		return (string)($route['id'] ?? ($route['@self']['id'] ?? ''));

	}//end referenceOf()

	/**
	 * Translate the local steps into the approval-route vocabulary.
	 *
	 * @param array<string, mixed> $route The route row.
	 *
	 * @return array<int, array<string, mixed>> The steps.
	 */
	private function stepsOf(array $route): array {
		$raw = ($route['steps'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		$steps = [];
		foreach ($raw as $index => $step) {
			if (is_array($step) === false) {
				continue;
			}

			$steps[] = $this->mapStep(step: $step, position: (int)$index);
		}

		return $steps;

	}//end stepsOf()


	/**
	 * Translate one local step into the approval-route vocabulary.
	 *
	 * @param array<string, mixed> $step     The local step.
	 * @param integer              $position Its position in the list, zero-based.
	 *
	 * @return array<string, mixed> The mapped step.
	 */
	private function mapStep(array $step, int $position): array {
		$mapped = ['stageType' => $this->stageTypeOf(step: $step)];

		foreach (self::STEP_FIELDS as $field) {
			$value = ($step[$field] ?? null);
			if ($value !== null && $value !== '') {
				$mapped[$field] = $value;
			}
		}

		$actorType = trim((string)($step['actorType'] ?? ''));
		if ($actorType !== '') {
			$mapped['actorType'] = (self::ACTOR_TYPES[$actorType] ?? 'role');
		}

		// A step with no `order` would collapse the sequence on the other side,
		// where order IS the sign-off sequence. Losing it does not produce a
		// broken route; it produces a plausible one in the wrong order, which is
		// a signature chain with the wrong person at the end. The array position
		// is the only remaining evidence of what that sequence was.
		if (isset($mapped['order']) === false) {
			$mapped['order'] = ($position + 1);
		}

		return $mapped;

	}//end mapStep()

	/**
	 * Map one local step type onto an approval-route stage type.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string The stage type.
	 */
	private function stageTypeOf(array $step): string {
		$local = trim((string)($step['type'] ?? ($step['stepType'] ?? '')));

		return (self::STAGE_TYPES[$local] ?? 'endorsement');

	}//end stageTypeOf()

	/**
	 * The first command-event class that actually exists.
	 *
	 * @return string|null The event FQN, or null when the decision app is absent.
	 */
	private function resolveEventClass(): ?string {
		foreach (self::APPROVAL_ROUTE_REQUESTED_EVENTS as $candidate) {
			if (class_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;

	}//end resolveEventClass()

}//end class
