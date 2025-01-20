<?php

namespace Webkul\RestApi\Http\Controllers\V1\Admin\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Webkul\RestApi\Helpers\Jobs\ProcessProductBatch;

class BulkProductController
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        $validationRules = [
            "sku" => "required|string",
            "type" => "required|in:simple,configurable",
            "parent_sku" => "nullable|string",
            "attribute_family_code" => "required|string",
            "name" => "required|string",
            "url_key" => "required|string",
            "short_description" => "required|string",
            "description" => "required|string",
            "tax_category_name" => "nullable|string",
            "product_number" => "nullable|string",
            "status" => "required|boolean",
            "manage_stock" => "nullable|boolean",
            "visible_individually" => "required|boolean",
            "price" => "required|numeric|min:0",
            "cost" => "numeric|min:0",
            "weight" => "required|numeric|min:0",
        ];
        $errors = [];
        foreach ($data as $index => $product) {
            $validator = Validator::make($product, $validationRules);

            if ($validator->fails()) {
                $errors[$product['sku'] ?? "product_{$index}"] = $validator->errors()->toArray();
            }
        }

        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        ProcessProductBatch::dispatch($data);

        return response()->json([
            'message' => trans('rest-api::app.admin.catalog.products.create-success'),
        ]);
    }
}
