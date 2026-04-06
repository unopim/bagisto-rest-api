<?php

namespace Webkul\RestApi\Http\Controllers\V1\Admin\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Webkul\RestApi\Helpers\Jobs\ProcessProductBatch;

class BulkProductController
{
    /**
     * Attribute type mappings
     */
    public $attributeTypeFields = [
        'text'        => 'text_value',
        'textarea'    => 'text_value',
        'price'       => 'float_value',
        'boolean'     => 'boolean_value',
        'select'      => 'integer_value',
        'multiselect' => 'text_value',
        'datetime'    => 'datetime_value',
        'date'        => 'date_value',
        'file'        => 'text_value',
        'image'       => 'text_value',
        'checkbox'    => 'text_value',
    ];

    /**
     * Get the validation rules that apply to the request.
     */
    public function getRule()
    {
        $prefix = DB::getTablePrefix();
        $attributes = DB::table('attributes')->get();
        $rules = [
            'type'                  => 'required|in:simple,configurable',
            'attribute_family_code' => 'required|string',
        ];

        foreach ($attributes as $attribute) {
            $ruleSet = [];

            if ($attribute->is_required) {
                $ruleSet[] = 'required';
            } else {
                continue;
            }

            if ($attribute->is_unique) {
                if ($attribute->code != 'url_key') {
                    $ruleSet[] = "unique:product_attribute_values,attribute_id,{$attribute->id}";
                }
            }

            switch ($this->attributeTypeFields[$attribute->type] ?? null) {
                case 'text_value':
                    // $ruleSet[] = 'string';
                    break;
                case 'float_value':
                    $ruleSet[] = 'numeric|min:0';
                    break;
                case 'boolean_value':
                    $ruleSet[] = 'boolean';
                    break;
                case 'integer_value':
                    $ruleSet[] = 'integer|min:0';
                    break;
                case 'datetime_value':
                    $ruleSet[] = 'date_format:Y-m-d H:i:s';
                    break;
                case 'date_value':
                    $ruleSet[] = 'date_format:Y-m-d';
                    break;
            }

            $rules[$attribute->code] = implode('|', $ruleSet);
        }

        return $rules;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $errors = [];
        $validProducts = [];
        $data = $request->all();
        $validationRules = $this->getRule();

        foreach ($data as $index => $product) {
            $validator = Validator::make($product, $validationRules);

            if ($validator->fails()) {
                $errors[$product['sku'] ?? "product_{$index}"] = $validator->errors()->toArray();
            } else {
                $validProducts[] = $product;
            }
        }

        if (! empty($validProducts)) {
            ProcessProductBatch::dispatch($validProducts);
        }

        if (! empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        return response()->json([
            'message' => trans('rest-api::app.admin.catalog.products.create-success'),
        ]);
    }
}
