<?php

declare(strict_types=1);

use yii\db\Migration;

class m261123_160000_create_bill_no_seq extends Migration
{
    public function safeUp(): void
    {
        $this->execute('CREATE SEQUENCE IF NOT EXISTS bill_no_seq START WITH 1 INCREMENT BY 1');
    }

    public function safeDown(): void
    {
        $this->execute('DROP SEQUENCE IF EXISTS bill_no_seq');
    }
}
