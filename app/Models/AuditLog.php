<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed> $context
 * @property string|null $ip_address
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'context', 'ip_address'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
