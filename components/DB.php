<?php

namespace app\components;
use Yii;

class DB
{
    public static function select(string $sql, array $params = []): array
    {
        return Yii::$app->db->createCommand($sql, $params)->queryAll();
    }

    public static function selectOne(string $sql, array $params = []): array|false
    {
        return Yii::$app->db->createCommand($sql, $params)->queryOne();
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        return Yii::$app->db->createCommand($sql, $params)->queryScalar();
    }
    
    public static function statement(string $sql, array $params = []): int
    {
        return Yii::$app->db->createCommand($sql, $params)->execute();
    }
}