<?php

/**
 * Read a `casePlanState` blob into what OpenRegister's `ensureItems()` takes.
 *
 * Pure: no storage, no OpenRegister. It decodes the blob, refuses what the
 * drain may not guess at (design.md section 3, "Unmappable cases"), puts each
 * recorded state onto its definition node and turns the event log into the
 * history entries OpenRegister imports as audit rows.
 *
 * It lives outside `Service\Cmmn` on purpose: that namespace is deleted in
 * the removal release (task 3.1) and the structural guard of task 4.2 fails on
 * any reference to it. The drain is the one piece of blob access that guard
 * allowlists until then, and it retires with the `casePlanState` property.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CasePlanDrain
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CasePlanDrain;

use UnexpectedValueException;

/**
 * Decodes and checks one case's blob against the definition that backs it.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
class CasePlanBlob {

	/**
	 * The six plan-item states. Identical on both sides (design.md section 1),
	 * so a recorded state outside them is a blob nobody can carry verbatim.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['available', 'enabled', 'disabled', 'active', 'completed', 'terminated'];

	/**
	 * An ISO 8601 date-time with an offset: the only moment OpenRegister
	 * imports. Checked here so the refusal names the case, not the call.
	 *
	 * @var string
	 */
	private const MOMENT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

	/**
	 * Decode the blob as stored on the case.
	 *
	 * The engine wrote it JSON-encoded into a `type: string` property and
	 * OpenRegister may hand it back decoded, so both are read.
	 *
	 * @param mixed $raw The case's `casePlanState` value.
	 *
	 * @return array{planItemStates: array<string, string>, caseFile: array<string, mixed>, eventLog: array<int, mixed>}|null
	 *         The blob, or null when the case carries none.
	 *
	 * @throws UnexpectedValueException `blob_undecodable` when it is there but unreadable.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function decode(mixed $raw): ?array {
		if ($raw === null || $raw === '' || $raw === []) {
			return null;
		}

		$decoded = $raw;
		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			throw new UnexpectedValueException('blob_undecodable');
		}

		$parts = [];
		foreach (['planItemStates', 'caseFile', 'eventLog'] as $part) {
			$parts[$part] = ($decoded[$part] ?? []);
			if (is_array($parts[$part]) === false) {
				throw new UnexpectedValueException('blob_undecodable');
			}
		}

		$clean = [];
		foreach ($parts['planItemStates'] as $key => $state) {
			$clean[(string)$key] = (string)$state;
		}

		return ['planItemStates' => $clean, 'caseFile' => $parts['caseFile'], 'eventLog' => array_values($parts['eventLog'])];
	}//end decode()

	/**
	 * Put every recorded state onto its node, refusing what cannot be carried.
	 *
	 * @param array<string, mixed>  $definition The converted definition (`settings`, nested `items`).
	 * @param array<string, string> $states     The blob's item states, keyed by item id.
	 *
	 * @return array<string, mixed> The definition with `state` on every recorded node.
	 *
	 * @throws UnexpectedValueException `unknown_state:<key>` or `orphan_item:<key>`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function withStates(array $definition, array $states): array {
		foreach ($states as $key => $state) {
			if (in_array($state, self::STATES, true) === false) {
				throw new UnexpectedValueException('unknown_state:' . $key);
			}
		}

		$keys = $this->keysOf(nodes: ($definition['items'] ?? []));
		foreach (array_keys($states) as $key) {
			if (in_array((string)$key, $keys, true) === false) {
				throw new UnexpectedValueException('orphan_item:' . $key);
			}
		}

		$definition['items'] = $this->stamp(nodes: ($definition['items'] ?? []), states: $states);

		return $definition;
	}//end withStates()

	/**
	 * Turn the blob's event log into OpenRegister history entries.
	 *
	 * @param array<int, mixed>    $eventLog   The blob's `eventLog`.
	 * @param array<string, mixed> $definition The converted definition.
	 *
	 * @return array<int, array<string, string>> Entries `{item, from?, to, at}`.
	 *
	 * @throws UnexpectedValueException `orphan_item:<key>` or `unreadable_moment:<index>`.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function history(array $eventLog, array $definition): array {
		$keys = $this->keysOf(nodes: ($definition['items'] ?? []));
		$history = [];
		foreach ($eventLog as $index => $entry) {
			if (is_array($entry) === false) {
				throw new UnexpectedValueException('unreadable_event:' . $index);
			}

			$item = trim((string)($entry['itemId'] ?? ''));
			if (in_array($item, $keys, true) === false) {
				throw new UnexpectedValueException('orphan_item:' . $item);
			}

			$moment = trim((string)($entry['at'] ?? ''));
			if (preg_match(self::MOMENT, $moment) !== 1) {
				throw new UnexpectedValueException('unreadable_moment:' . $index);
			}

			$row = ['item' => $item, 'to' => (string)($entry['to'] ?? ''), 'at' => $moment];
			$from = trim((string)($entry['from'] ?? ''));
			if ($from !== '') {
				$row['from'] = $from;
			}

			$history[] = $row;
		}//end foreach

		return $history;
	}//end history()

	/**
	 * Which recorded states the rows do not hold.
	 *
	 * The verification before the blob is cleared (design.md section 3, step
	 * 6): every item the blob records must exist as a row in the same state.
	 * An existing row is never touched by `ensureItems()`, so a case whose
	 * plan was already projected in another state shows up here, and keeps
	 * its blob.
	 *
	 * @param array<string, string>            $states The blob's item states.
	 * @param array<int, array<string, mixed>> $rows   The rows OpenRegister holds.
	 *
	 * @return array<int, string> One `key: blob -> row` line per mismatch; empty when it matches.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function mismatches(array $states, array $rows): array {
		$held = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$held[(string)($row['key'] ?? '')] = (string)($row['state'] ?? '');
			}
		}

		$out = [];
		foreach ($states as $key => $state) {
			$actual = ($held[(string)$key] ?? 'missing');
			if ($actual !== $state) {
				$out[] = sprintf('%s: %s -> %s', $key, $state, $actual);
			}
		}

		return $out;
	}//end mismatches()

	/**
	 * Every node key in the tree.
	 *
	 * @param mixed $nodes A level of nodes.
	 *
	 * @return array<int, string> The keys.
	 */
	private function keysOf(mixed $nodes): array {
		if (is_array($nodes) === false) {
			return [];
		}

		$keys = [];
		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$keys[] = (string)($node['key'] ?? '');
			$keys = array_merge($keys, $this->keysOf(nodes: ($node['children'] ?? [])));
		}

		return $keys;
	}//end keysOf()

	/**
	 * Copy each recorded state onto its node, at every depth.
	 *
	 * @param mixed                 $nodes  A level of nodes.
	 * @param array<string, string> $states The recorded states.
	 *
	 * @return array<int, mixed> The level, stamped.
	 */
	private function stamp(mixed $nodes, array $states): array {
		if (is_array($nodes) === false) {
			return [];
		}

		$out = [];
		foreach ($nodes as $node) {
			if (is_array($node) === true) {
				$key = (string)($node['key'] ?? '');
				if (isset($states[$key]) === true) {
					$node['state'] = $states[$key];
				}

				if (isset($node['children']) === true) {
					$node['children'] = $this->stamp(nodes: $node['children'], states: $states);
				}
			}

			$out[] = $node;
		}

		return $out;
	}//end stamp()
}//end class
