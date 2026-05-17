<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuVariant;
use App\Models\Category;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index()
    {
        $menus = Menu::with(['category', 'variants' => function ($q) {
            $q->where('is_available', true);
        }])->where('is_available', true)->get();

        return response()->json($menus);
    }

    public function all()
    {
        $menus = Menu::with('category', 'variants')->get();
        return response()->json($menus);
    }

    public function store(Request $request)
{
    $request->validate([
        'category_id' => 'required|exists:categories,id',
        'name' => 'required|string|max:255',
        'price' => 'nullable|integer|min:0',
        'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        'variants' => 'nullable',
    ]);

    $imagePath = null;
    if ($request->hasFile('image')) {
        $imagePath = $request->file('image')->store('menus', 'public');
    }

    $variants = [];
    if ($request->has('variants')) {
        $decoded = is_string($request->variants)
            ? json_decode($request->variants, true)
            : $request->variants;
        if (is_array($decoded)) {
            $variants = $decoded;
        }
    }

    $menu = Menu::create([
        'category_id' => $request->category_id,
        'name' => $request->name,
        'price' => count($variants) > 0 ? null : $request->price,
        'image' => $imagePath,
    ]);

    foreach ($variants as $variant) {
        if (empty($variant['name'])) continue;
        MenuVariant::create([
            'menu_id' => $menu->id,
            'name' => $variant['name'],
            'price' => (int) ($variant['price'] ?? 0),
        ]);
    }

    $menu->load('category', 'variants');

    AuditLog::record($request->user()->id, 'create_menu', 'Menu', $menu->id, [
        'name' => $menu->name,
    ]);

    return response()->json($menu, 201);
}

public function update(Request $request, Menu $menu)
{
    $request->validate([
        'category_id' => 'sometimes|exists:categories,id',
        'name' => 'sometimes|string|max:255',
        'price' => 'nullable|integer|min:0',
        'is_available' => 'sometimes|boolean',
        'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        'variants' => 'nullable',
    ]);

    $oldData = ['name' => $menu->name, 'price' => $menu->price, 'is_available' => $menu->is_available];

    $updateData = $request->only('category_id', 'name', 'price', 'is_available');

    if ($request->hasFile('image')) {
        // Delete old image
        if ($menu->image) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($menu->image);
        }
        $updateData['image'] = $request->file('image')->store('menus', 'public');
    }

    $menu->update($updateData);

    if ($request->has('variants')) {
        $variants = is_string($request->variants) ? json_decode($request->variants, true) : $request->variants;

        if (is_array($variants)) {
            $incomingIds = collect($variants)->pluck('id')->filter()->toArray();
            $menu->variants()->whereNotIn('id', $incomingIds)->delete();

            foreach ($variants as $variantData) {
                if (isset($variantData['id'])) {
                    MenuVariant::where('id', $variantData['id'])->update([
                        'name' => $variantData['name'],
                        'price' => $variantData['price'],
                        'is_available' => $variantData['is_available'] ?? true,
                    ]);
                } else {
                    MenuVariant::create([
                        'menu_id' => $menu->id,
                        'name' => $variantData['name'],
                        'price' => $variantData['price'],
                    ]);
                }
            }

            if (count($variants) > 0) {
                $menu->update(['price' => null]);
            }
        }
    }

    $menu->load('category', 'variants');

    AuditLog::record($request->user()->id, 'update_menu', 'Menu', $menu->id, [
        'old' => $oldData,
        'new' => ['name' => $menu->name, 'price' => $menu->price, 'is_available' => $menu->is_available],
    ]);

    return response()->json($menu);
}

    public function destroy(Request $request, Menu $menu)
    {
        AuditLog::record($request->user()->id, 'delete_menu', 'Menu', $menu->id, [
            'name' => $menu->name,
        ]);

        $menu->delete();
        return response()->json(['message' => 'Menu dihapus']);
    }

    public function categories()
    {
        return response()->json(Category::all());
    }
}
