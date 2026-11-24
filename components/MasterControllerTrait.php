<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use app\models\AuditLog;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

trait MasterControllerTrait
{
    protected function requireRoles(array $allowedRoles): ?array
    {
        $user = Yii::$app->user->identity;
        $roleCode = $user?->role?->code;

        if ($roleCode === null || !in_array($roleCode, $allowedRoles, true)) {
            Yii::$app->response->statusCode = 403;
            return [
                'rc' => 403,
                'rm' => 'Akses ditolak: Anda tidak memiliki hak akses untuk aksi ini',
                'data' => null,
            ];
        }

        return null;
    }

    protected function getRequestParams(): array
    {
        $request = Yii::$app->request;
        return array_merge(
            (array)$request->getBodyParams(),
            (array)$request->post()
        );
    }

    protected function normalizeCode(mixed $code): string
    {
        return strtoupper(trim((string)$code));
    }

    protected function applyCodeNameSearch(
        ActiveQuery $query,
        string $search,
        mixed $isActiveParam = null,
        bool $hasIsActive = true
    ): void {
        $trimmed = trim($search);
        if ($trimmed !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'code', $trimmed],
                ['ilike', 'name', $trimmed],
            ]);
        }

        if ($hasIsActive && $isActiveParam !== null && $isActiveParam !== '') {
            $query->andWhere(['is_active' => filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    protected function resolveSortOrder(array $allowedSort, array $defaultOrder): array
    {
        $request = Yii::$app->request;
        $sortParam = $request->get('sort');
        $orderParam = strtolower((string)$request->get('order', 'asc'));

        if ($sortParam !== null && is_string($sortParam)) {
            $isDesc = str_starts_with($sortParam, '-') || $orderParam === 'desc';
            $cleanSort = ltrim($sortParam, '-');
            if (isset($allowedSort[$cleanSort])) {
                return [$allowedSort[$cleanSort] => $isDesc ? SORT_DESC : SORT_ASC];
            }
        }

        return $defaultOrder;
    }

    protected function buildPagedResponse(ActiveQuery $query, array $orderBy, callable $mapper): array
    {
        $request = Yii::$app->request;
        $pageParam = $request->get('page');
        $sizeParam = $request->get('size');

        $size = $sizeParam !== null ? (int)$sizeParam : 10;
        if ($size <= 0) {
            $size = 10;
        }

        if ($pageParam === null || (int)$pageParam <= 1) {
            $displayPage = 0;
            $offset = 0;
        } else {
            $displayPage = (int)$pageParam - 1;
            $offset = $displayPage * $size;
        }

        $totalElements = (int)(clone $query)->count();
        $totalPages = $size > 0 ? (int)ceil($totalElements / $size) : 0;

        $rows = $query->orderBy($orderBy)
            ->limit($size)
            ->offset($offset)
            ->all();

        $data = [];
        foreach ($rows as $row) {
            $data[] = $mapper($row);
        }

        return [
            'page' => $displayPage,
            'size' => $size,
            'totalPages' => $totalPages,
            'totalElements' => $totalElements,
            'data' => $data,
        ];
    }

    protected function performDelete(ActiveRecord $model, string $auditEntity, array $oldValues): array
    {
        $id = (int)$model->getAttribute('id');

        try {
            $model->delete();
        } catch (\yii\db\IntegrityException $e) {
            Yii::$app->response->statusCode = 409;
            return [
                'rc'   => 409,
                'rm'   => 'Data tidak dapat dihapus karena masih terkait dengan data lain',
                'data' => null,
            ];
        }

        AuditLog::log($auditEntity, $id, 'DELETE', $oldValues, null);

        return [
            'rc'   => 200,
            'rm'   => 'Berhasil',
            'data' => null,
        ];
    }

    protected function performSoftDelete(ActiveRecord $model, string $auditEntity, array $oldValues): array
    {
        return $this->performDelete($model, $auditEntity, $oldValues);
    }
}
