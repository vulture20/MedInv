<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registry row for a metadata source plugin (briefing 8.2). `provider_key`
 * must match a key registered in the MetadataProviderRegistry
 * (app/Domain/Metadata/MetadataProviderRegistry.php).
 */
#[Fillable(['provider_key', 'name', 'media_type', 'enabled', 'config', 'priority'])]
class MetadataPlugin extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }
}
