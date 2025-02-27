<?php

namespace Webkul\RestApi\Docs\Admin\Controllers\Catalog;

class BulkProductController
{
    /**
     * @OA\Post(
     *      path="/api/v1/admin/catalog/bulk-products",
     *      operationId="storeBulkProduct",
     *      tags={"BulkProducts"},
     *      summary="Store multiple catalog products",
     *      description="Bulk API endpoints differ from other REST endpoints in that they combine multiple calls of the same type into an array and execute them as a single request. The endpoint handler splits the array into individual entities and writes them as separate messages to the message queue.",
     *      security={ {"sanctum_admin": {} }},
     *
     *      @OA\RequestBody(
     *
     *          @OA\MediaType(
     *              mediaType="application/json",
     *
     *              @OA\Schema(
     *                  type="array",
     *
     *                  @OA\Items(
     *                      type="object",
     *
     *                      @OA\Property(
     *                          property="sku",
     *                          description="Unique identifier for the product",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="name",
     *                          description="Name of the product",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="url_key",
     *                          description="URL key of the product",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="type",
     *                          description="Product type",
     *                          type="string",
     *                          example="simple",
     *                          enum={"simple", "configurable", "virtual", "grouped", "downloadable", "bundle"}
     *                      ),
     *                      @OA\Property(
     *                          property="parent_sku",
     *                          description="Parent SKU for variants (nullable for simple products)",
     *                          type="string",
     *                          nullable=true,
     *                          example=null
     *                      ),
     *                      @OA\Property(
     *                          property="channel",
     *                          description="Sales channel for the product",
     *                          type="string",
     *                          example="default"
     *                      ),
     *                      @OA\Property(
     *                          property="locale",
     *                          description="Locale code",
     *                          type="string",
     *                          example="en"
     *                      ),
     *                      @OA\Property(
     *                          property="status",
     *                          description="Product status (1 for enabled, 0 for disabled)",
     *                          type="string",
     *                          example="1"
     *                      ),
     *                      @OA\Property(
     *                          property="guest_checkout",
     *                          description="Allow guest checkout (1 for yes, 0 for no)",
     *                          type="string",
     *                          example="1"
     *                      ),
     *                      @OA\Property(
     *                          property="visible_individually",
     *                          description="Visibility status of the product",
     *                          type="string",
     *                          example="1"
     *                      ),
     *                      @OA\Property(
     *                          property="inventories",
     *                          description="Inventory details in the format 'channel=quantity'",
     *                          type="string",
     *                          example="default=1"
     *                      ),
     *                      @OA\Property(
     *                          property="attribute_family_code",
     *                          description="Code of the attribute family",
     *                          type="string",
     *                          example="default"
     *                      ),
     *                      @OA\Property(
     *                          property="meta_title",
     *                          description="Meta title for the product",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="meta_keywords",
     *                          description="Meta keywords for SEO",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="meta_description",
     *                          description="Meta description for SEO",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="short_description",
     *                          description="Short description of the product",
     *                          type="string",
     *                          example="Short description for Product1"
     *                      ),
     *                      @OA\Property(
     *                          property="description",
     *                          description="Detailed description of the product",
     *                          type="string",
     *                          example="Detailed description for Product1"
     *                      ),
     *                      @OA\Property(
     *                          property="tax_category_name",
     *                          description="Tax category name (leave empty if not applicable)",
     *                          type="string",
     *                          example=""
     *                      ),
     *                      @OA\Property(
     *                          property="product_number",
     *                          description="Unique product number",
     *                          type="string",
     *                          example="product1"
     *                      ),
     *                      @OA\Property(
     *                          property="manage_stock",
     *                          description="Stock management status (0 for disabled, 1 for enabled)",
     *                          type="integer",
     *                          example=0
     *                      ),
     *                      @OA\Property(
     *                          property="price",
     *                          description="Price of the product",
     *                          type="string",
     *                          format="float",
     *                          example="100.0000"
     *                      ),
     *                      @OA\Property(
     *                          property="cost",
     *                          description="Cost of the product",
     *                          type="string",
     *                          format="float",
     *                          example="24.2100"
     *                      ),
     *                      @OA\Property(
     *                          property="weight",
     *                          description="Weight of the product",
     *                          type="string",
     *                          example="34"
     *                      ),
     *                      @OA\Property(
     *                          property="images",
     *                          description="URL of the product image",
     *                          type="string",
     *                          format="uri",
     *                          example="https://images.pexels.com/photos/546819/pexels-photo-546819.jpeg"
     *                      ),
     *                      @OA\Property(
     *                          property="categories",
     *                          description="Comma-separated categories",
     *                          type="string",
     *                          example="Activities"
     *                      ),
     *                      @OA\Property(
     *                          property="related_skus",
     *                          description="Comma-separated SKUs for related products",
     *                          type="string",
     *                          example="product1,product2,product3"
     *                      ),
     *                      @OA\Property(
     *                          property="cross_sell_skus",
     *                          description="Comma-separated SKUs for cross-sell products",
     *                          type="string",
     *                          example="product1,product2,product3"
     *                      ),
     *                      @OA\Property(
     *                          property="up_sell_skus",
     *                          description="Comma-separated SKUs for up-sell products",
     *                          type="string",
     *                          example="product1,product2,product3"
     *                      )
     *                  ),
     *                  example={{
     *                      "sku": "product1",
     *                      "name": "product1",
     *                      "url_key": "product1",
     *                      "type": "simple",
     *                      "parent_sku": null,
     *                      "channel": "default",
     *                      "locale": "en",
     *                      "status": "1",
     *                      "guest_checkout": "1",
     *                      "visible_individually": "1",
     *                      "inventories": "default=1",
     *                      "attribute_family_code": "default",
     *                      "meta_title": "product1",
     *                      "meta_keywords": "product1",
     *                      "meta_description": "product1",
     *                      "short_description": "Short description for Product1",
     *                      "description": "Detailed description for Product1",
     *                      "tax_category_name": "",
     *                      "product_number": "product1",
     *                      "manage_stock": 0,
     *                      "price": "100.0000",
     *                      "cost": "24.2100",
     *                      "weight": "34",
     *                      "images": "https://images.pexels.com/photos/546819/pexels-photo-546819.jpeg",
     *                      "categories": "Activities",
     *                      "related_skus": "product1,product2,product3",
     *                      "cross_sell_skus": "product1,product2,product3",
     *                      "up_sell_skus": "product1,product2,product3"
     *                  },
     *                  {
     *                      "sku": "product2",
     *                      "name": "product2",
     *                      "url_key": "product2",
     *                      "type": "simple",
     *                      "parent_sku": null,
     *                      "channel": "default",
     *                      "locale": "en",
     *                      "status": "1",
     *                      "guest_checkout": "1",
     *                      "visible_individually": "1",
     *                      "inventories": "default=1",
     *                      "attribute_family_code": "default",
     *                      "meta_title": "product2",
     *                      "meta_keywords": "product2",
     *                      "meta_description": "product2",
     *                      "short_description": "Short description for Product2",
     *                      "description": "Detailed description for Product2",
     *                      "tax_category_name": "",
     *                      "product_number": "product2",
     *                      "manage_stock": 1,
     *                      "price": "200.0000",
     *                      "cost": "50.0000",
     *                      "weight": "45",
     *                      "images": "https://images.pexels.com/photos/546820/pexels-photo-546820.jpeg",
     *                      "categories": "Electronics",
     *                      "related_skus": "product1,product2,product3",
     *                      "cross_sell_skus": "product1,product2,product3",
     *                      "up_sell_skus": "product1,product2,product3"
     *                  }}
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(
     *                  property="message",
     *                  type="string",
     *                  example="Products have been successfully added."
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=401,
     *          description="Unauthenticated"
     *      )
     * )
     */
    public function store() {}
}
