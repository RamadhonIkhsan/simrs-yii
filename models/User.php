<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * User model
 *
 * @property int $id
 * @property string $username
 * @property string|null $email
 * @property string $password_hash
 * @property string $full_name
 * @property bool $is_active
 * @property string|null $last_login_at
 * @property string $created_at
 * @property int|null $created_by
 * @property string $updated_at
 * @property int|null $updated_by
 */
class User extends ActiveRecord implements IdentityInterface
{
    public ?int $role_id = null;
    public ?string $role_name = null;

    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return '{{%users}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['username', 'roles', 'password_hash', 'full_name'], 'required'],
            [['is_active'], 'boolean'],
            [['email'], 'email'],
            [['last_login_at', 'created_at', 'updated_at'], 'safe'],
            [['roles', 'created_by', 'updated_by'], 'integer'],
            [['username', 'email', 'password_hash', 'full_name'], 'string', 'max' => 255],
            [['username'], 'unique'],
        ];
    }

    /**
     * Relasi ke model Role
     */
    public function getRole()
    {
        return $this->hasOne(Role::class, ['id' => 'roles']);
    }

    public function afterFind(): void
    {
        parent::afterFind();

        if ($this->role_id === null && $this->roles !== null) {
            $this->role_id = (int)$this->roles;
        }

        if ($this->role_name === null && $this->roles !== null) {
            $this->role_name = $this->role?->name;
        }
    }

    public function getRoleCode(): ?string
    {
        return $this->role?->code;
    }

    /**
     * Getter alias $user->name -> $user->full_name
     */
    public function getName(): ?string
    {
        return $this->full_name;
    }

    /**
     * Setter alias $user->name = 'Admin' -> $user->full_name = 'Admin'
     */
    public function setName(?string $value): void
    {
        $this->full_name = $value;
    }

    /**
     * Exclude sensitive attributes from array / JSON serialization.
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['password_hash']);
        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id): ?static
    {
        return static::findOne(['id' => $id]);
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        $payload = \app\components\JwtHelper::validateToken((string)$token);
        if ($payload === null || empty($payload['uid']) || (isset($payload['scope']) && $payload['scope'] === 'print')) {
            return null;
        }

        return static::findOne([
            'id' => $payload['uid'],
            'is_active' => true,
        ]);
    }

    /**
     * Finds active user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername(string $username): ?static
    {
        return static::find()
            ->alias('u')
            ->select([
                'u.*',
                'r.id as role_id',
                'r.name as role_name'
            ])
            ->leftJoin('roles r', 'r.id = u.roles')
            ->where(['ILIKE', 'username', $username])
            ->one();
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): int|string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey(): ?string
    {
        return md5((string)$this->id . (string)$this->password_hash . (string)$this->created_at);
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey): bool
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword(string $password): bool
    {
        if (empty($this->password_hash)) {
            return false;
        }

        try {
            if (Yii::$app->security->validatePassword($password, $this->password_hash)) {
                return true;
            }
            if (Yii::$app->security->validatePassword(md5($password), $this->password_hash)) {
                return true;
            }
        } catch (\Throwable) {
            // Not a bcrypt/argon2 hash or invalid format, continue to fallback
        }

        return hash_equals((string)$this->password_hash, $password)
            || hash_equals((string)$this->password_hash, md5($password))
            || password_verify($password, (string)$this->password_hash)
            || password_verify(md5($password), (string)$this->password_hash);
    }

    /**
     * Generates password hash from password with bcrypt(md5($password)) format
     *
     * @param string $password
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash(md5($password));
    }
}
