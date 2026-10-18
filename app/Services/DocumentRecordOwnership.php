<?php

namespace App\Services;

use App\Models\DocumentScan;
use UnexpectedValueException;

class DocumentRecordOwnership
{
    /** @return list<array<string, mixed>> */
    public function plan(mixed $records, int $pageCount): array
    {
        if (! is_array($records) || ! array_is_list($records)) {
            throw new UnexpectedValueException('The document has an invalid logical record plan.');
        }

        $plan = [];
        $occupied = [];
        foreach ($records as $index => $record) {
            if (! is_array($record) || ! is_string($record['anchor'] ?? null) || trim($record['anchor']) === ''
                || ! is_array($record['regions'] ?? null) || ! array_is_list($record['regions']) || $record['regions'] === []) {
                throw new UnexpectedValueException('A logical record has no source evidence.');
            }
            $regions = [];
            foreach ($record['regions'] as $region) {
                $page = $region['page_number'] ?? null;
                $box = $region['bbox'] ?? null;
                if (! is_int($page) || $page < 1 || $page > $pageCount || (isset($regions[$page]))
                    || ($box !== null && ! $this->validBox($box))) {
                    throw new UnexpectedValueException('A logical record has an invalid page region.');
                }
                $regions[$page] = ['page_number' => $page, 'bbox' => $box];
                if ($box !== null) {
                    foreach ($occupied[$page] ?? [] as $other) {
                        if ($box['x'] < $other['x'] + $other['width'] && $other['x'] < $box['x'] + $box['width']
                            && $box['y'] < $other['y'] + $other['height'] && $other['y'] < $box['y'] + $box['height']) {
                            throw new UnexpectedValueException('Logical record regions overlap on a page.');
                        }
                    }
                    $occupied[$page][] = $box;
                }
            }
            ksort($regions);
            $plan[] = ['id' => 'r'.($index + 1), 'anchor' => $record['anchor'], 'regions' => array_values($regions)];
        }

        return $plan;
    }

    /** @return array<string, string|null> */
    public function validate(DocumentScan $scan, array $findings, mixed $entries, ?array $range = null): array
    {
        $plan = $scan->record_plan;
        // Existing scans have no plan; their original V1 observations remain readable.
        if ($plan === null) {
            return [];
        }
        $plan = $this->plan($plan, $findings['document']['page_count']);
        $records = [];
        foreach ($plan as $record) {
            $records[$record['id']] = array_column($record['regions'], null, 'page_number');
        }
        if ($range !== null) {
            $present = [];
            foreach ($records as $id => $pages) {
                if (array_intersect(array_keys($pages), range($range[0], $range[1])) !== []) {
                    $present[$id] = true;
                }
            }
        } else {
            $present = array_fill_keys(array_keys($records), true);
        }

        $items = [];
        foreach (['sections', 'entities', 'fields', 'tables', 'content_blocks'] as $group) {
            foreach ($findings[$group] as $item) {
                $pages = $item['page_numbers'] ?? [$item['evidence']['page_number']];
                $items[$item['id']] = ['group' => $group, 'item' => $item, 'pages' => $pages];
            }
        }
        if ($entries === null && count($present) === 1) {
            $entries = [];
        }
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new UnexpectedValueException('The extraction omitted record ownership.');
        }
        $owners = [];
        foreach ($entries as $entry) {
            $id = $entry['finding_id'] ?? null;
            $recordId = $entry['record_id'] ?? null;
            if (! is_string($id) || ! isset($items[$id]) || array_key_exists($id, $owners)
                || ($recordId !== null && (! is_string($recordId) || ! isset($present[$recordId])))) {
                throw new UnexpectedValueException('The extraction returned invalid record ownership.');
            }
            if ($recordId !== null) {
                foreach ($items[$id]['pages'] as $page) {
                    if (! isset($records[$recordId][$page])) {
                        throw new UnexpectedValueException('A finding was assigned outside its record pages.');
                    }
                }
            }
            $owners[$id] = $recordId;
        }
        foreach ($items as $id => $detail) {
            if (array_key_exists($id, $owners)) {
                continue;
            }
            $candidates = array_keys(array_filter($present, fn (bool $included, string $recordId): bool => $included
                && array_diff($detail['pages'], array_keys($records[$recordId])) === [], ARRAY_FILTER_USE_BOTH));
            $owners[$id] = count($candidates) === 1 ? $candidates[0] : null;
        }
        if ($range === null && array_diff(array_keys($records), array_filter(array_values($owners))) !== []) {
            throw new UnexpectedValueException('A logical record has no extracted findings.');
        }
        foreach ($items as $id => $detail) {
            $item = $detail['item'];
            if ($owners[$id] !== null && in_array($detail['group'], ['sections', 'entities'], true)) {
                foreach ($detail['pages'] as $page) {
                    if (count(array_filter($records, fn (array $pages): bool => isset($pages[$page]))) < 2) {
                        continue;
                    }
                    $linked = false;
                    foreach ($items as $childId => $child) {
                        $evidence = $child['item']['evidence'] ?? null;
                        if ($owners[$childId] === $owners[$id] && ($child['item'][$detail['group'] === 'entities' ? 'entity_id' : 'section_id'] ?? null) === $id
                            && ($evidence['page_number'] ?? null) === $page && $this->validBox($evidence['bbox'] ?? null)
                            && $this->validBox($records[$owners[$id]][$page]['bbox'] ?? null)
                            && $this->boxWithin($evidence['bbox'], $records[$owners[$id]][$page]['bbox'])) {
                            $linked = true;
                            break;
                        }
                    }
                    if (! $linked) {
                        throw new UnexpectedValueException('A shared-page entity or section has no supporting record evidence.');
                    }
                }
            }
            if ($owners[$id] !== null && isset($item['evidence'])) {
                $page = $item['evidence']['page_number'];
                $onPage = array_filter($records, fn (array $pages): bool => isset($pages[$page]));
                if (count($onPage) > 1) {
                    $regionBox = $records[$owners[$id]][$page]['bbox'];
                    $evidenceBox = $item['evidence']['bbox'] ?? null;
                    if ($regionBox === null || ! $this->validBox($evidenceBox)
                        || ! $this->boxWithin($evidenceBox, $regionBox)) {
                        throw new UnexpectedValueException('A shared-page finding lacks evidence within its record region.');
                    }
                }
            }
            foreach (['parent_id', 'section_id', 'entity_id'] as $key) {
                $target = $item[$key] ?? null;
                if ($target !== null && $owners[$id] !== $owners[$target]) {
                    throw new UnexpectedValueException('A finding references another logical record.');
                }
            }
            foreach ($item['related_entity_ids'] ?? [] as $target) {
                if ($owners[$id] !== $owners[$target]) {
                    throw new UnexpectedValueException('Related entities cross logical records.');
                }
            }
        }

        return $owners;
    }

    private function boxWithin(array $box, array $region): bool
    {
        return $box['x'] >= $region['x'] && $box['y'] >= $region['y']
            && $box['x'] + $box['width'] <= $region['x'] + $region['width']
            && $box['y'] + $box['height'] <= $region['y'] + $region['height'];
    }

    private function validBox(mixed $box): bool
    {
        if (! is_array($box) || count($box) !== 4 || array_diff(['x', 'y', 'width', 'height'], array_keys($box)) !== []) {
            return false;
        }
        foreach ($box as $value) {
            if (! is_int($value) && ! is_float($value) || $value < 0 || $value > 1) {
                return false;
            }
        }

        return $box['x'] + $box['width'] <= 1 && $box['y'] + $box['height'] <= 1;
    }
}
