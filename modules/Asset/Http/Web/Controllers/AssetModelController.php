<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Asset\Application\Actions\CreateAssetModel;
use Modules\Asset\Application\Actions\DeleteAssetModel;
use Modules\Asset\Application\Actions\UpdateAssetModel;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Http\Web\Requests\AssetModelRequest;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;

final class AssetModelController extends ModuleController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::ASSET_VIEW);

        $search = trim((string) $request->query('q'));
        $category = (string) $request->query('category');

        $models = AssetModel::query()
            ->with('category')
            ->withCount('assets')
            ->when($category !== '', fn ($query) => $query->whereHas('category', fn ($query) => $query->where('ulid', $category)))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('brand', 'ilike', "%{$search}%")
                ->orWhere('model_name', 'ilike', "%{$search}%")))
            ->orderBy('brand')
            ->orderBy('model_name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (AssetModel $model): array => [
                'ulid' => $model->ulid,
                'name' => $model->displayName(),
                'category' => $model->category->name,
                'tracking' => $model->category->tracking_mode->label(),
                'assets_count' => $model->getAttribute('assets_count'),
                'is_active' => $model->is_active,
            ]);

        return Inertia::render('asset/models/Index', [
            'models' => $models,
            'filters' => ['q' => $search, 'category' => $category],
            'categories' => $this->categoryOptions(),
            'can' => ['manage' => $this->user()->can(Permissions::ASSET_CATALOG_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);

        return Inertia::render('asset/models/Form', ['model' => null, 'categories' => $this->categoryOptions()]);
    }

    public function store(AssetModelRequest $request, CreateAssetModel $action): RedirectResponse
    {
        $data = $request->validated();

        $category = AssetCategory::query()->where('ulid', (string) $data['category'])->first();

        if (! $category instanceof AssetCategory) {
            throw ValidationException::withMessages(['category' => 'Kategori tidak ditemukan.']);
        }

        $action->handle([
            'asset_category_id' => $category->id,
            'brand' => (string) $data['brand'],
            'model_name' => (string) $data['model_name'],
            'is_active' => (bool) $data['is_active'],
        ]);

        $this->success('Jenis aset ditambahkan.');

        return to_route('asset.models.index');
    }

    public function edit(AssetModel $model): Response
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);

        return Inertia::render('asset/models/Form', [
            'model' => [
                'ulid' => $model->ulid,
                'category' => $model->category->name,
                'brand' => $model->brand,
                'model_name' => $model->model_name,
                'is_active' => $model->is_active,
            ],
            'categories' => [],
        ]);
    }

    public function update(AssetModelRequest $request, AssetModel $model, UpdateAssetModel $action): RedirectResponse
    {
        $data = $request->validated();

        $action->handle($model, [
            'brand' => (string) $data['brand'],
            'model_name' => (string) $data['model_name'],
            'is_active' => (bool) $data['is_active'],
        ]);

        $this->success('Jenis aset diperbarui.');

        return to_route('asset.models.index');
    }

    public function destroy(AssetModel $model, DeleteAssetModel $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);

        try {
            $action->handle($model);
            $this->success('Jenis aset dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('asset.models.index');
    }

    /**
     * @return array<int, array{value: string, label: string, tracking: string}>
     */
    private function categoryOptions(): array
    {
        return AssetCategory::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (AssetCategory $category): array => [
                'value' => $category->ulid,
                'label' => $category->name,
                'tracking' => $category->tracking_mode->label(),
            ])
            ->values()
            ->all();
    }
}
