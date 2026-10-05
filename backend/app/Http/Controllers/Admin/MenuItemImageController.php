<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderMenuImagesRequest;
use App\Http\Requests\Admin\UploadMenuImagesRequest;
use App\Http\Resources\MenuItemResource;
use App\Menu\MenuImageService;
use App\Models\MenuItem;
use App\Models\MenuItemImage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MenuItemImageController extends Controller
{
    public function store(UploadMenuImagesRequest $request, Store $store, MenuItem $menuItem, MenuImageService $images): JsonResponse
    {
        $paths = collect($request->file('images'))->map(fn ($file) => $file->getRealPath())->all();

        $images->add($menuItem, $paths);

        return (new MenuItemResource($menuItem->load('images')))->response()->setStatusCode(201);
    }

    public function destroy(Store $store, MenuItem $menuItem, MenuItemImage $image, MenuImageService $images): MenuItemResource
    {
        Gate::authorize('manageSettings', $store);

        $images->delete($image);

        return new MenuItemResource($menuItem->load('images'));
    }

    public function reorder(ReorderMenuImagesRequest $request, Store $store, MenuItem $menuItem, MenuImageService $images): MenuItemResource
    {
        $images->reorder($menuItem, $request->validated('ids'));

        return new MenuItemResource($menuItem->load('images'));
    }
}
