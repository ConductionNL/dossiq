<?php

/**
 * Runs a retired transition or task action through the nodes that replaced it.
 *
 * A case type declares its transition side effects and its task effects as
 * data: `{type: "notify", ...}`, `{type: "setField", ...}`. Those declarations
 * outlive the handlers that used to answer them, and a case type seeded last
 * year still names them. Rewriting stored flows does not reach them, because
 * they are not flows.
 *
 * So the transition path asks this class before it gives up on a type. It
 * looks the type up in {@see RetiredNodeMap} as `dossiq.<type>`, translates the
 * declaration with the same {@see RetiredNodeTranslator} the flow rewrite uses,
 * and runs the resulting steps through OpenRegister's node catalogue with the
 * case as the one item. One translation, two callers: a declaration and a flow
 * step that say the same thing do the same thing.
 *
 * It never throws. Like the handlers it stands in for, it answers with a
 * result row, because a failed side effect must not roll back the transition
 * that caused it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs retired declared actions as their replacement nodes.
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */
class RetiredActionRunner {

	/**
	 * The prefix a declared type takes to become a node type.
	 *
	 * @var string
	 */
	private const NODE_PREFIX = 'dossiq.';

	/**
	 * OpenRegister's node catalogue, resolved by name.
	 *
	 * @var string
	 */
	private const NODE_REGISTRY = 'OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry';

	/**
	 * The declared transition types that were retired.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_TYPES = ['sendEmail', 'notify', 'setField', 'evaluateDecision', 'webhook'];

	/**
	 * The context key OpenRegister's nodes read the acting user from.
	 *
	 * @var string
	 */
	private const RUN_AS = 'runAs';

	/**
	 * Constructor.
	 *
	 * @param RetiredNodeMap        $map        The retired-node table.
	 * @param RetiredNodeTranslator $translator Turns a declaration into steps.
	 * @param ContainerInterface    $container  Resolves OpenRegister's node catalogue, which may be absent.
	 * @param LoggerInterface       $logger     Logger.
	 */
	public function __construct(
		private readonly RetiredNodeMap $map,
		private readonly RetiredNodeTranslator $translator,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a declared type is one this class answers for.
	 *
	 * @param string $type The declared action type, such as `notify`.
	 *
	 * @return bool True when the type is retired.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function handles(string $type): bool {
		return $type !== '' && $this->map->rowFor(type: self::NODE_PREFIX . $type) !== null;
	}//end handles()

	/**
	 * The retired declared types that still run, because something replaces them.
	 *
	 * @return array<int, string> The declared types, such as `notify`.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function runnableTypes(): array {
		$types = [];
		foreach (self::DECLARED_TYPES as $type) {
			$row = $this->map->rowFor(type: self::NODE_PREFIX . $type);
			if ($row !== null && $row['replacement'] !== null) {
				$types[] = $type;
			}
		}

		return $types;
	}//end runnableTypes()

	/**
	 * Run one declared action as the steps that replace it.
	 *
	 * @param string               $type    The declared type.
	 * @param array<string, mixed> $action  The declaration.
	 * @param array<string, mixed> $case    The case.
	 * @param array<string, mixed> $context The transition or task context; `userId` is who acts.
	 *
	 * @return array{type: string, ok: bool, error?: string} The result row.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function run(string $type, array $action, array $case, array $context): array {
		try {
			$steps = $this->steps(type: $type, action: $action, context: $context);
		} catch (UnmappableStep $e) {
			$this->logger->warning(
				'Dossiq: a declared "' . $type . '" action cannot run as its replacement: ' . $e->getMessage(),
				['type' => $type, 'case' => (string)($case['id'] ?? '')]
			);
			return ['type' => $type, 'ok' => false, 'error' => 'retired_action_unmappable: ' . $e->getMessage()];
		}

		$registry = $this->registry();
		if ($registry === null) {
			return ['type' => $type, 'ok' => false, 'error' => 'retired_action_needs_openregister'];
		}

		$runContext = $context;
		if (trim((string)($runContext[self::RUN_AS] ?? '')) === '' && trim((string)($context['userId'] ?? '')) !== '') {
			$runContext[self::RUN_AS] = (string)$context['userId'];
		}

		$items = [['json' => $case]];
		try {
			foreach ($steps as $step) {
				$items = $registry->get(type: $step['type'])->execute(items: $items, config: $step['config'], context: $runContext);
			}
		} catch (Throwable $e) {
			$message = $e->getMessage();
			if ($message === '') {
				$message = 'action_failed';
			}

			return ['type' => $type, 'ok' => false, 'error' => $message];
		}

		return ['type' => $type, 'ok' => true];
	}//end run()

	/**
	 * The steps a declaration becomes.
	 *
	 * A transition knew its own label, and the retired handlers used it: as
	 * the mail's subject when none was written, and in the notification's
	 * title. It is handed to the translation the same way.
	 *
	 * @param string               $type    The declared type.
	 * @param array<string, mixed> $action  The declaration.
	 * @param array<string, mixed> $context The transition context.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>}> The steps.
	 *
	 * @throws UnmappableStep When the declaration has no faithful equivalent.
	 */
	private function steps(string $type, array $action, array $context): array {
		$row = $this->map->rowFor(type: self::NODE_PREFIX . $type);
		if ($row === null) {
			throw new UnmappableStep('"' . $type . '" is not a retired action');
		}

		$label = trim((string)($context['transitionLabel'] ?? ''));
		if ($label !== '') {
			$action += ['transitionLabel' => $label];
			if ($type === 'sendEmail') {
				$action += ['subject' => $label];
			}
		}

		if (isset($row['translation']) === true) {
			return $this->translator->translate(translation: $row['translation'], config: $action);
		}

		if ($row['replacement'] === null) {
			throw new UnmappableStep('nothing replaces it (' . $row['reason'] . ')');
		}

		return [['type' => $row['replacement'], 'config' => $action]];
	}//end steps()

	/**
	 * OpenRegister's node catalogue, or null when OpenRegister is absent.
	 *
	 * @return FlowNodeRegistry|null The catalogue.
	 */
	private function registry(): ?FlowNodeRegistry {
		if (class_exists(self::NODE_REGISTRY) === false) {
			return null;
		}

		try {
			$registry = $this->container->get(self::NODE_REGISTRY);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: OpenRegister node catalogue unavailable for a retired action', ['error' => $e->getMessage()]);
			return null;
		}

		if (($registry instanceof FlowNodeRegistry) === false) {
			return null;
		}

		return $registry;
	}//end registry()
}//end class
