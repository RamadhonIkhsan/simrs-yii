<?php

declare(strict_types=1);

namespace app\services\billing\sources;

interface BillSourceInterface
{
    /**
     * Collect billing items for a visit.
     * Each item must contain: item_type, ref_table, ref_id, description, qty, unit_price.
     *
     * @param int $visitId
     * @return array<array{item_type: string, ref_table: string, ref_id: int, description: string, qty: int, unit_price: float|string}>
     */
    public function collect(int $visitId): array;
}
