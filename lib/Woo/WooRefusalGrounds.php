<?php

/**
 * Dossiq Woo refusal grounds
 *
 * The one list of Woo refusal grounds, settled against the law in design D-2
 * of woo-refusal-grounds-list (decision 133) and seeded as `wooRefusalGround`
 * objects in dossiq's register. dossiq validates assessments against it, and
 * filinq and opencatalogi read it through this class (design D-3).
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the refusal grounds as the system, and never answers an empty list.
 *
 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
 */
class WooRefusalGrounds {

	use SearchesObjects;

	/**
	 * The register the grounds live in.
	 */
	public const REGISTER = 'dossiq';

	/**
	 * The schema slug of a ground.
	 */
	public const SCHEMA = 'wooRefusalGround';

	/**
	 * The version of the settled list, written as `groundsListVersion` on every
	 * row that stores grounds. MapWooRefusalGroundCodes skips a row carrying it,
	 * because six of dossiq's old codes are also new codes with another meaning.
	 */
	public const LIST_VERSION = '2026-10-09';

	/**
	 * The keys every ground is answered with, in this order.
	 */
	public const KEYS = [
		'id',
		'code',
		'article',
		'paragraph',
		'letter',
		'label',
		'description',
		'parent',
		'status',
		'legalSource',
		'kind',
		'citable',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reaches OpenRegister's object service.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every ground, in code order.
	 *
	 * @param bool $includeRetired Whether retired grounds are answered too.
	 *
	 * @return list<array<string, mixed>> The grounds, each with every key of {@see KEYS}.
	 *
	 * @throws WooRefusalGroundsUnavailable When the register cannot be read or holds no grounds.
	 *
	 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
	 */
	public function list(bool $includeRetired = false): array {
		$grounds = [];
		foreach ($this->readAll() as $row) {
			$ground = $this->shape(row: $row);
			if ($ground['code'] === '') {
				continue;
			}

			if ($includeRetired === false && $ground['status'] !== 'active') {
				continue;
			}

			$grounds[] = $ground;
		}

		usort(
			$grounds,
			static fn (array $left, array $right): int => strnatcmp($left['code'], $right['code'])
		);

		return $grounds;
	}//end list()

	/**
	 * One ground by its code, retired or not.
	 *
	 * @param string $code The ground's code, such as 5.1.2.e.
	 *
	 * @return array<string, mixed>|null The ground, or null when no ground carries the code.
	 *
	 * @throws WooRefusalGroundsUnavailable When the register cannot be read or holds no grounds.
	 *
	 * @spec openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#requirement-other-apps-read-the-list-through-one-named-method-req-wrg-007
	 */
	public function byCode(string $code): ?array {
		$code = trim($code);
		foreach ($this->list(includeRetired: true) as $ground) {
			if ($ground['code'] === $code) {
				return $ground;
			}
		}

		return null;
	}//end byCode()

	/**
	 * Every stored ground row, read as the system.
	 *
	 * @return list<array<string, mixed>> The rows.
	 *
	 * @throws WooRefusalGroundsUnavailable When the read fails or answers nothing.
	 */
	private function readAll(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new WooRefusalGroundsUnavailable('The Woo refusal grounds cannot be read: OpenRegister is not available.');
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: self::REGISTER,
				schema: self::SCHEMA,
				filters: ['_limit' => 1000],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooRefusalGrounds: the grounds could not be read',
				['error' => $e->getMessage()]
			);
			throw new WooRefusalGroundsUnavailable('The Woo refusal grounds cannot be read.', 0, $e);
		}

		// An empty answer is a register that was never seeded or a read that
		// lost its scope. Answering [] would tell a caller no ground exists.
		if ($rows === []) {
			throw new WooRefusalGroundsUnavailable('The Woo refusal grounds cannot be read: the register holds none.');
		}

		return array_values($rows);
	}//end readAll()

	/**
	 * One row as a ground with every key.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return array<string, mixed> The ground.
	 */
	private function shape(array $row): array {
		$parent = $this->textOrNull(value: ($row['parent'] ?? null));
		$kind = $this->textOrNull(value: ($row['kind'] ?? null));
		$status = 'active';
		if ((string)($row['status'] ?? '') === 'retired') {
			$status = 'retired';
		}

		return [
			'id' => (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? ''))),
			'code' => trim((string)($row['code'] ?? '')),
			'article' => (string)($row['article'] ?? ''),
			'paragraph' => (string)($row['paragraph'] ?? ''),
			'letter' => (string)($row['letter'] ?? ''),
			'label' => (string)($row['label'] ?? ''),
			'description' => (string)($row['description'] ?? ''),
			'parent' => $parent,
			'status' => $status,
			'legalSource' => (string)($row['legalSource'] ?? ''),
			'kind' => $kind,
			'citable' => (($row['citable'] ?? false) === true),
		];
	}//end shape()

	/**
	 * A trimmed text, or null when it is blank or absent.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The text, or null.
	 */
	private function textOrNull(mixed $value): ?string {
		$text = trim((string)($value ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()
}//end class
