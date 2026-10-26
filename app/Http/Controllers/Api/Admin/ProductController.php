<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $validator = Validator::make($request->all(), [
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Product::where('tenant_id', $tenantId);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Products retrieved successfully.',
            'data' => $query->latest()->paginate(20),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->input('tenant_id', $request->input('business_id'));

        $validator = Validator::make($request->all(), [
            'tenant_id' => ['sometimes', 'required_without:business_id', 'integer', 'exists:tenants,id'],
            'business_id' => ['sometimes', 'required_without:tenant_id', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:200'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where(
                    fn ($q) => $q->where('tenant_id', $tenantId)
                ),
            ],
            'category' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['tenant_id'] = $tenantId;
        unset($data['business_id']);
        $data['currency'] = strtoupper($data['currency'] ?? 'INR');
        $data['stock_quantity'] = $data['stock_quantity'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $product = Product::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    public function show(int $product): JsonResponse
    {
        $record = Product::find($product);

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $record = Product::find($product);

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where(fn ($q) => $q->where('tenant_id', $record->tenant_id ?? $record->business_id))
                    ->ignore($record->id),
            ],
            'category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'attributes' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $record->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $record->fresh(),
        ]);
    }

    public function destroy(int $product): JsonResponse
    {
        $record = Product::find($product);

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }
}