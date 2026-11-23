<?php

declare(strict_types=1);

namespace app\services\billing\sources;

use app\models\BillItem;
use app\models\VisitProcedure;
use yii\db\Query;

class ProcedureSource implements BillSourceInterface
{
    public function collect(int $visitId): array
    {
        $rows = (new Query())
            ->select([
                'vp.id',
                'vp.qty',
                'vp.unit_tariff',
                'procedure_name' => 'p.name',
            ])
            ->from(['vp' => 'visit_procedures'])
            ->innerJoin(['p' => 'procedures'], 'p.id = vp.procedure_id')
            ->where([
                'vp.visit_id' => $visitId,
                'vp.status' => VisitProcedure::STATUS_ACTIVE,
            ])
            ->orderBy(['vp.id' => SORT_ASC])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'item_type' => BillItem::TYPE_PROCEDURE,
                'ref_table' => 'visit_procedures',
                'ref_id' => (int)$row['id'],
                'description' => (string)$row['procedure_name'],
                'qty' => (int)$row['qty'],
                'unit_price' => (float)$row['unit_tariff'],
            ];
        }

        return $items;
    }
}
