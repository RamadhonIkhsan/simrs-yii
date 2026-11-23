<?php

declare(strict_types=1);

namespace app\services\billing\sources;

use app\models\BillItem;
use yii\db\Query;

class DrugSource implements BillSourceInterface
{
    public function collect(int $visitId): array
    {
        $rows = (new Query())
            ->select([
                'pi.id',
                'pi.qty',
                'pi.unit_price',
                'drug_name' => 'd.name',
            ])
            ->from(['pi' => 'prescription_items'])
            ->innerJoin(['rx' => 'prescriptions'], 'rx.id = pi.prescription_id')
            ->innerJoin(['d' => 'drugs'], 'd.id = pi.drug_id')
            ->where([
                'rx.visit_id' => $visitId,
                'rx.status' => 'DISPENSED',
            ])
            ->orderBy(['pi.id' => SORT_ASC])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'item_type' => BillItem::TYPE_DRUG,
                'ref_table' => 'prescription_items',
                'ref_id' => (int)$row['id'],
                'description' => (string)$row['drug_name'],
                'qty' => (int)$row['qty'],
                'unit_price' => (float)$row['unit_price'],
            ];
        }

        return $items;
    }
}
