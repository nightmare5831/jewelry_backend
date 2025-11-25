<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if ($user && $user->isSeller()) {
            // Web App (Authenticated Seller) - Show their own products
            $products = Product::where('seller_id', $user->id)
                ->latest()
                ->paginate(20);
        } elseif ($user && $user->isAdmin()) {
            // Web App (Admin) - Show all products
            $products = Product::latest()->paginate(20);
        } else {
            // Android App (Public) - Show only approved & active products
            $products = Product::where('status', 'approved')
                ->where('is_active', true)
                ->latest()
                ->paginate(20);
        }

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->isSeller()) {
            return response()->json(['error' => 'Only sellers can create products'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string',
            'subcategory' => 'nullable|string',
            'gold_weight_grams' => 'required|numeric|min:0.001',
            'gold_karat' => 'required|in:18k,22k,24k',
            'base_price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'images' => 'nullable|json',
            'model_3d_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get current gold price
        $latestGoldPrice = \App\Models\GoldPrice::getLatest();
        $currentGoldPrice = $latestGoldPrice?->price_gram_18k ?? 99.17;

        $product = Product::create([
            'seller_id' => $user->id,
            'name' => $request->name,
            'description' => $request->description,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'gold_weight_grams' => $request->gold_weight_grams,
            'gold_karat' => $request->gold_karat,
            'base_price' => $request->base_price,
            'current_price' => $request->base_price,
            'initial_gold_price' => $currentGoldPrice,
            'stock_quantity' => $request->stock_quantity,
            'images' => $request->images ?? json_encode([]),
            'model_3d_url' => $request->model_3d_url,
            'status' => 'pending',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Product created successfully. Awaiting admin approval.',
            'product' => $product,
        ], 201);
    }

    public function show($id)
    {
        $user = Auth::user();
        $product = Product::with(['seller'])->findOrFail($id);

        if ($product->seller_id !== $user->id && !$product->isApproved() && !$user->isAdmin()) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $product = Product::findOrFail($id);

        if ($product->seller_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($product->isApproved()) {
            return response()->json(['error' => 'Cannot edit approved products. Please contact admin.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category' => 'sometimes|string',
            'subcategory' => 'nullable|string',
            'gold_weight_grams' => 'sometimes|numeric|min:0.001',
            'gold_karat' => 'sometimes|in:18k,22k,24k',
            'base_price' => 'sometimes|numeric|min:0',
            'stock_quantity' => 'sometimes|integer|min:0',
            'images' => 'nullable|json',
            'model_3d_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->update($request->all());

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product,
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();
        $product = Product::findOrFail($id);

        if ($product->seller_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }
}
