<?php

declare(strict_types=1);

namespace Cg\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $form
 * @property string|null $context
 * @property array<string, mixed> $data
 * @property string|null $rejected_for
 */
final class FormSubmission extends Model
{
    protected $table = 'form_submissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'notified_at' => 'immutable_datetime',
        ];
    }

    /** Genuine submissions: everything the guard did not reject. */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereNull('rejected_for');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->whereNotNull('rejected_for');
    }

    public function scopeForm(Builder $query, string $form): Builder
    {
        return $query->where('form', $form);
    }

    public function wasRejected(): bool
    {
        return $this->rejected_for !== null;
    }
}
