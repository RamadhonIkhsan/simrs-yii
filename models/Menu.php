<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * @property int $menu_id
 * @property int|null $menu_parent_id
 * @property string $menu
 * @property int $lvl
 * @property int $seq
 * @property string|null $url
 * @property string|null $icon
 */
class Menu extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%menu}}';
    }

    public static function primaryKey(): array
    {
        return ['menu_id'];
    }

    public function rules(): array
    {
        return [
            [['menu_id', 'menu', 'lvl', 'seq'], 'required'],
            [['menu_id', 'menu_parent_id', 'lvl', 'seq'], 'integer'],
            [['menu', 'url', 'icon'], 'string', 'max' => 255],
        ];
    }

    public function getParent()
    {
        return $this->hasOne(self::class, ['menu_id' => 'menu_parent_id']);
    }

    public function getChildren()
    {
        return $this->hasMany(self::class, ['menu_parent_id' => 'menu_id'])
            ->orderBy(['seq' => SORT_ASC, 'menu_id' => SORT_ASC]);
    }

    public function getRoles()
    {
        return $this->hasMany(Role::class, ['id' => 'roles_id'])
            ->viaTable('{{%menu_privilege}}', ['menu_id' => 'menu_id']);
    }

    /**
     * @return Menu[]
     */
    public static function findByRoleId(int $roleId): array
    {
        return static::find()
            ->innerJoin('{{%menu_privilege}} mp', '{{%menu}}.menu_id = mp.menu_id')
            ->where(['mp.roles_id' => $roleId])
            ->orderBy([
                '{{%menu}}.lvl' => SORT_ASC,
                '{{%menu}}.seq' => SORT_ASC,
                '{{%menu}}.menu_id' => SORT_ASC,
            ])
            ->all();
    }

    /**
     * Transform flat menus array into nested tree hierarchy.
     */
    public static function buildTree(array $menus): array
    {
        $byId = [];
        foreach ($menus as $m) {
            $id = (int)$m->menu_id;
            $byId[$id] = [
                'menu_id'        => $id,
                'menu_parent_id' => $m->menu_parent_id !== null ? (int)$m->menu_parent_id : null,
                'menu'           => $m->menu,
                'lvl'            => (int)$m->lvl,
                'seq'            => (int)$m->seq,
                'url'            => $m->url,
                'icon'           => $m->icon,
                'children'       => [],
            ];
        }

        $tree = [];
        foreach ($byId as $id => &$node) {
            $parentId = $node['menu_parent_id'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return array_values($tree);
    }

    /**
     * Format menus as flat array.
     */
    public static function formatFlat(array $menus): array
    {
        return array_map(function (Menu $m): array {
            return [
                'menu_id'        => (int)$m->menu_id,
                'menu_parent_id' => $m->menu_parent_id !== null ? (int)$m->menu_parent_id : null,
                'menu'           => $m->menu,
                'lvl'            => (int)$m->lvl,
                'seq'            => (int)$m->seq,
                'url'            => $m->url,
                'icon'           => $m->icon,
            ];
        }, $menus);
    }
}
