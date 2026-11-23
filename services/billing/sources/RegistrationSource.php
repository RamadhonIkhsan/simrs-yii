<?php

declare(strict_types=1);

namespace app\services\billing\sources;

use app\models\BillItem;
use yii\db\Query;

class RegistrationSource implements BillSourceInterface
{
    public function collect(int $visitId): array
    {
        $visit = (new Query())
            ->select(['v.id', 'v.registration_fee', 'poli_name' => 'p.name'])
            ->from(['v' => 'visits'])
            ->leftJoin(['p' => 'polis'], 'p.id = v.poli_id')
            ->where(['v.id' => $visitId])
            ->one();

        if (!$visit) {
            return [];
        }

        $fee = (float)$visit['registration_fee'];
        if ($fee <= 0) {
            return [];
        }

        return [
            [
                'item_type' => BillItem::TYPE_REGISTRATION,
                'ref_table' => 'visits',
                'ref_id' => (int)$visit['id'],
                'description' => 'Biaya pendaftaran - ' . ($visit['poli_name'] ?? ''),
                'qty' => 1,
                'unit_price' => $fee,
            ],
        ];
    }
}
