<?php

declare(strict_types=1);

use yii\db\Migration;

class m261123_163000_create_payment_no_seq extends Migration
{
    public function safeUp(): void
    {
        $this->execute('CREATE SEQUENCE IF NOT EXISTS payment_no_seq START WITH 1 INCREMENT BY 1');
    }

    public function safeDown(): void
    {
        $this->execute('DROP SEQUENCE IF EXISTS payment_no_seq');
    }
}
