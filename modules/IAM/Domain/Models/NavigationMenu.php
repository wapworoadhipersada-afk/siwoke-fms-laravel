<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * One entry of the sidebar: a page (it has a route) or a group that holds pages (it has none).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $key
 * @property string $section
 * @property string $label
 * @property string|null $route_name
 * @property string|null $url
 * @property string|null $icon
 * @property string|null $permission_name
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_custom
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'key', 'section', 'label', 'route_name', 'url', 'icon', 'permission_name', 'sort_order', 'is_active', 'is_custom'])]
final class NavigationMenu extends Model
{
    /**
     * A group leads nowhere itself; it only holds pages.
     */
    public function isGroup(): bool
    {
        return $this->route_name === null && $this->url === null;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'navigation_menu_role')
            ->withPivot(['is_visible', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_custom' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
