<?php

/**
 * Map every older checklist shape onto the one `inspectionChecklistTemplate`.
 *
 * Pure: no storage. Three shapes come in and one goes out (design D1 of
 * inspection-checklists-onto-task):
 *
 *  - stack A, `inspectieChecklist`: flat items answered by label;
 *  - stack C, `inspectionChecklist` with `checklistItem` rows (or the editor's
 *    inline items), answered by item uuid;
 *  - the settings editor's flat view, which keeps its shape so the admin tab
 *    does not change while its storage does.
 *
 * And one goes back: {@see toFlat()} renders a template in the editor's flat
 * view.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

/**
 * Converts checklist shapes to and from the template schema.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
 */
class InspectionTemplateMapper {

	/**
	 * The template schema's slug.
	 *
	 * @var string
	 */
	public const SCHEMA = 'inspectionChecklistTemplate';

	/**
	 * Older item types onto the template's `responseType`.
	 *
	 * @var array<string, string>
	 */
	private const TYPES = [
		'yes_no_na' => 'yes_no_na',
		'ja_nee_nvt' => 'yes_no_na',
		'boolean' => 'yes_no_na',
		'text' => 'text',
		'tekst' => 'text',
		'getal' => 'getal',
		'meting' => 'meting',
		'photo' => 'photo',
		'foto' => 'photo',
		'meerkeuze' => 'meerkeuze',
		'enum' => 'meerkeuze',
	];

	/**
	 * Stack A (`inspectieChecklist`) onto a template.
	 *
	 * Stack A's reports name an item by its label (`InspectionPanel` sets
	 * `itemId: item.label`), so the label is the item id.
	 *
	 * @param array<string, mixed> $checklist The stack A object.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function fromStackA(array $checklist): array {
		$items = [];
		foreach ($this->listOf(value: ($checklist['items'] ?? [])) as $index => $item) {
			$label = (string)($item['label'] ?? '');
			$items[] = $this->item(item: $item, id: $label, label: $label, type: (string)($item['type'] ?? ''), order: $index);
		}

		$status = (string)($checklist['status'] ?? 'draft');
		if ($status === 'archived') {
			$status = 'retired';
		}

		return $this->template(
			source: $checklist,
			name: (string)($checklist['name'] ?? ''),
			caseType: (string)($checklist['caseType'] ?? ''),
			status: $status,
			items: $items,
			legacyRef: 'inspectieChecklist/' . $this->idOf(object: $checklist)
		);
	}//end fromStackA()

	/**
	 * Stack C (`inspectionChecklist`) onto a template.
	 *
	 * Its `items` are `checklistItem` uuids, resolved through `$rows`, or the
	 * inline items the settings editor posted. An unresolvable uuid is left out
	 * and named in the result's `unresolved`, so the caller can refuse it.
	 *
	 * @param array<string, mixed>                $checklist The stack C object.
	 * @param array<string, array<string, mixed>> $rows      `checklistItem` rows by uuid.
	 *
	 * @return array{template: array<string, mixed>, unresolved: array<int, string>} The template, and what could not be read.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function fromStackC(array $checklist, array $rows): array {
		$items = [];
		$unresolved = [];
		foreach ($this->rawItems(value: ($checklist['items'] ?? [])) as $index => $raw) {
			$item = $raw;
			$id = '';
			if (is_string($raw) === true) {
				$id = $raw;
				$item = ($rows[$raw] ?? null);
			}

			if (is_array($item) === false) {
				$unresolved[] = (string)$raw;
				continue;
			}

			if ($id === '') {
				$id = (string)($item['id'] ?? $item['uuid'] ?? ('item-' . ((int)$index + 1)));
			}

			$items[] = $this->item(
				item: $item,
				id: $id,
				label: (string)($item['question'] ?? $item['label'] ?? ''),
				type: (string)($item['type'] ?? ''),
				order: (int)$index
			);
		}//end foreach

		$status = 'retired';
		if (($checklist['active'] ?? false) === true) {
			$status = 'active';
		}

		return [
			'template' => $this->template(
				source: $checklist,
				name: (string)($checklist['name'] ?? ''),
				caseType: (string)($checklist['caseTypeRef'] ?? $checklist['caseType'] ?? ''),
				status: $status,
				items: $items,
				legacyRef: 'inspectionChecklist/' . $this->idOf(object: $checklist)
			),
			'unresolved' => $unresolved,
		];
	}//end fromStackC()

	/**
	 * The settings editor's flat view onto a template.
	 *
	 * @param array<string, mixed> $flat The editor payload (`name`, `caseTypeRef`, `active`, `items`).
	 *
	 * @return array<string, mixed> The template, without a legacy reference.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function fromFlat(array $flat): array {
		$converted = $this->fromStackC(checklist: $flat, rows: []);
		$template = $converted['template'];
		unset($template['legacyRef']);
		if (array_key_exists('active', $flat) === false) {
			$template['status'] = 'active';
			$template['active'] = true;
		}

		return $template;
	}//end fromFlat()

	/**
	 * A template rendered in the editor's flat view.
	 *
	 * @param array<string, mixed> $template The template object.
	 *
	 * @return array<string, mixed> `id`, `name`, `caseTypeRef`, `version`, `active`, `status`, `items`.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function toFlat(array $template): array {
		$items = [];
		foreach ($this->listOf(value: ($template['sections'] ?? [])) as $section) {
			foreach ($this->listOf(value: ($section['items'] ?? [])) as $item) {
				$item['question'] = (string)($item['label'] ?? '');
				$item['type'] = (string)($item['responseType'] ?? '');
				$item['photoRequired'] = (($item['photoRequired'] ?? 'nooit') !== 'nooit');
				$items[] = $item;
			}
		}

		return [
			'id' => $this->idOf(object: $template),
			'name' => (string)($template['name'] ?? ''),
			'caseTypeRef' => (string)($template['caseType'] ?? ''),
			'version' => (int)($template['version'] ?? 1),
			'active' => (($template['status'] ?? '') === 'active'),
			'status' => (string)($template['status'] ?? 'draft'),
			'items' => $items,
		];
	}//end toFlat()

	/**
	 * The `responseType` an older item type maps onto; `text` when unknown.
	 *
	 * @param string $type The older type.
	 *
	 * @return string The response type.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function responseType(string $type): string {
		return (self::TYPES[$type] ?? 'text');
	}//end responseType()

	/**
	 * One template item.
	 *
	 * @param array<string, mixed> $item  The source item.
	 * @param string               $id    Its answer key.
	 * @param string               $label Its question.
	 * @param string               $type  Its older type.
	 * @param integer              $order Its position.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function item(array $item, string $id, string $label, string $type, int $order): array {
		$out = [
			'id' => $id,
			'order' => (int)($item['order'] ?? ($order + 1)),
			'label' => $label,
			'responseType' => $this->responseType(type: $type),
			'required' => (($item['required'] ?? false) === true),
			'photoRequired' => $this->photoGate(value: ($item['photoRequired'] ?? $item['fotoRequired'] ?? false)),
		];

		$choices = ($item['choices'] ?? $item['options'] ?? null);
		if (is_array($choices) === true && $choices !== []) {
			$out['choices'] = array_values(array_map(static fn ($choice): string => (string)$choice, $choices));
		}

		foreach (['helpText', 'parent'] as $key) {
			if (trim((string)($item[$key] ?? '')) !== '') {
				$out[$key] = (string)$item[$key];
			}
		}

		if (is_numeric($item['weight'] ?? null) === true) {
			$out['weight'] = (float)$item['weight'];
		}

		return $out;
	}//end item()

	/**
	 * The photo gate from an older boolean or a template value.
	 *
	 * @param mixed $value `true`/`false`, or `nooit`/`if_no`/`altijd` (or `bij_nee`).
	 *
	 * @return string The gate.
	 */
	private function photoGate(mixed $value): string {
		if ($value === true) {
			return 'if_no';
		}

		$gate = (string)$value;
		if ($gate === 'bij_nee') {
			return 'if_no';
		}

		if (in_array($gate, ['nooit', 'if_no', 'altijd'], true) === true) {
			return $gate;
		}

		return 'nooit';
	}//end photoGate()

	/**
	 * A template around one section of items.
	 *
	 * @param array<string, mixed>             $source    The source object.
	 * @param string                           $name      Its name.
	 * @param string                           $caseType  Its case type uuid, or empty.
	 * @param string                           $status    draft, active or retired.
	 * @param array<int, array<string, mixed>> $items     The items.
	 * @param string                           $legacyRef The source reference.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(array $source, string $name, string $caseType, string $status, array $items, string $legacyRef): array {
		$template = [
			'name' => $name,
			'version' => max(1, (int)($source['version'] ?? 1)),
			'status' => $status,
			'active' => ($status === 'active'),
			'sections' => [['order' => 1, 'name' => $name, 'items' => $items]],
			'legacyRef' => $legacyRef,
		];
		if ($caseType !== '') {
			$template['caseType'] = $caseType;
		}

		return $template;
	}//end template()

	/**
	 * The uuid of an object, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string The uuid.
	 */
	private function idOf(array $object): string {
		$self = ($object['@self'] ?? []);
		if (is_array($self) === true && (string)($self['id'] ?? '') !== '') {
			return (string)$self['id'];
		}

		return (string)($object['id'] ?? $object['uuid'] ?? '');
	}//end idOf()

	/**
	 * The array members of a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, array<string, mixed>> The members that are arrays.
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn ($member): bool => is_array($member)));
	}//end listOf()

	/**
	 * Every member of a list, strings and arrays alike.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, mixed> The members.
	 */
	private function rawItems(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end rawItems()
}//end class
