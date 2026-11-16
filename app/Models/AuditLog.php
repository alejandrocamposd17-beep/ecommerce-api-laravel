<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Registro de auditoría de acciones administrativas (quién cambió qué y cuándo).
 * Es de solo escritura: no se actualiza ni se elimina desde la API.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra una acción. $changes guarda solo los campos modificados (antes y después).
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(Request $request, string $action, ?Model $subject = null, ?array $changes = null): self
    {
        return static::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * Diferencias entre los valores originales y los nuevos de un modelo ya guardado.
     *
     * @param  array<string, mixed>  $original
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $original, Model $model): array
    {
        $diff = [];
        foreach ($model->getChanges() as $field => $new) {
            if ($field === 'updated_at') {
                continue;
            }
            $diff[$field] = ['old' => $original[$field] ?? null, 'new' => $new];
        }

        return $diff;
    }
}
