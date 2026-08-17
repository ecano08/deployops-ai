<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'name', 'slug', 'description'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public static function uniqueSlugFor(Workspace $workspace, string $name, ?int $exceptCustomerId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'customer';
        }

        $slug = $base;
        $suffix = 1;

        while ($workspace->customers()
            ->when($exceptCustomerId !== null, fn ($query) => $query->whereKeyNot($exceptCustomerId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
