<?php

declare(strict_types=1);

namespace app\services\billing\sources;

use app\models\BillItem;
use yii\db\Query;

class LabSource implements BillSourceInterface
{
    public function collect(int $visitId): array
    {
        $rows = (new Query())
            ->select([
                'lri.id',
                'lri.unit_tariff',
                'test_name' => 'lt.name',
            ])
            ->from(['lri' => 'lab_request_items'])
            ->innerJoin(['lr' => 'lab_requests'], 'lr.id = lri.lab_request_id')
            ->innerJoin(['lt' => 'lab_tests'], 'lt.id = lri.lab_test_id')
            ->where([
                'lr.visit_id' => $visitId,
                'lri.status' => 'ACTIVE',
            ])
            ->andWhere(['<>', 'lr.status', 'CANCELLED'])
            ->orderBy(['lri.id' => SORT_ASC])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'item_type' => BillItem::TYPE_LAB,
                'ref_table' => 'lab_request_items',
                'ref_id' => (int)$row['id'],
                'description' => (string)$row['test_name'],
                'qty' => 1,
                'unit_price' => (float)$row['unit_tariff'],
            ];
        }

        return $items;
    }
}
