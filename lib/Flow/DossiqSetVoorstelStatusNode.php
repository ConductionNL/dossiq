<?php

/**
 * Move a voorstel to a status, as the last thing a projected approval route does.
 *
 * A projected route is `askParaaf × N → requestDecision`, and until this node
 * existed it wrote nothing back: a voorstel driven by a flow collected every
 * paraaf and then sat in `in_parafering` forever, because the status
 * transitions lived in BesluitvormingParafeerService and the flow never reached
 * them. That is why the projections shipped disabled — not only because both
 * drivers would run, but because the flow half could not finish the job.
 *
 * 🔴 THE STATUS IS CHECKED AGAINST THE SCHEMA'S OWN LIST, NOT ASSUMED.
 *
 * `proposal.status` is a closed enum and OpenRegister runs hard validation by
 * default, so an undeclared value fails the save far from the node that chose
 * it. dossiq#1609 is what that looks like unnoticed: two of three outcomes in
 * the old service wrote statuses the schema rejects, and one of them meant a
 * returned voorstel was read as an approved one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Flow
 * @package  OCA\Dossiq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Flow;

use OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sets a voorstel's status from within a flow.
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 */
class DossiqSetVoorstelStatusNode implements IFlowNode {

	/**
	 * Constructor.
	 *
	 * @param ParaafFlowLinkage $linkage Writes the status, and refuses one the schema does not declare.
	 * @param IL10N             $l10n    Translations.
	 * @param LoggerInterface   $logger  The logger.
	 */
	public function __construct(
		private readonly ParaafFlowLinkage $linkage,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The namespaced node id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getId(): string {
		return 'dossiq.setVoorstelStatus';

	}//end getId()

	/**
	 * The node's display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Set voorstel status');

	}//end getDisplayName()

	/**
	 * What the node does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Move the voorstel to a status, named rather than referenced by id.');

	}//end getDescription()

	/**
	 * The node's icon.
	 *
	 * @return string The icon name.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function getIcon(): string {
		return 'FileCheckOutline';

	}//end getIcon()

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * @param integer $scope The flow scope.
	 *
	 * @return boolean Always true.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The scope is part of
	 * IFlowNode's contract; this node is valid in every scope.
	 */
	public function isAvailableForScope(int $scope): bool {
		return true;

	}//end isAvailableForScope()

	/**
	 * Refuse a configuration that names no status.
	 *
	 * @param array<string, mixed> $config The node config.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no status is named.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['status'] ?? '')) === '') {
			throw new RuntimeException('dossiq.setVoorstelStatus needs a status');
		}

	}//end validateConfig()

	/**
	 * Move every voorstel in the items to the configured status.
	 *
	 * @param array<int, mixed>    $items   The input items.
	 * @param array<string, mixed> $config  The node config.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<int, mixed> The items, unchanged.
	 *
	 * @throws RuntimeException When the config names no status, or one the schema rejects.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The run context is part of
	 * IFlowNode's contract; this node reads the voorstel from its items.
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		$status = trim((string)$config['status']);

		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$json = (array)($item['json'] ?? []);
			$proposalId = trim((string)($json['id'] ?? ($json['uuid'] ?? '')));
			if ($proposalId === '') {
				// A step that cannot name what it is moving has nothing to move.
				// Skipped rather than raised: the run has already collected
				// every paraaf, and failing here would strand it at the end.
				$this->logger->warning(
					'Dossiq setVoorstelStatus: an item named no voorstel, so nothing was moved',
					['status' => $status],
				);
				continue;
			}

			// The linkage refuses a status the proposal schema does not
			// declare, and that refusal is deliberately NOT caught here: a flow asking
			// for an impossible status is an authoring error, and swallowing it
			// would leave the voorstel silently where it was.
			$moved = $this->linkage->setStatus(proposalId: $proposalId, status: $status);

			$this->logger->info(
				'Dossiq setVoorstelStatus: voorstel ' . $proposalId . ' → ' . $status,
				['moved' => $moved],
			);
		}//end foreach

		return $items;

	}//end execute()
}//end class
