<?php

namespace Webkul\RestApi\Helpers\Importers\Product;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\DataTransfer\Helpers\Importers\Product\Importer as BaseImporter;
use Webkul\Product\Jobs\ElasticSearch\UpdateCreateIndex as UpdateCreateElasticSearchIndexJob;
use Webkul\Product\Jobs\UpdateCreateInventoryIndex as UpdateCreateInventoryIndexJob;
use Webkul\Product\Jobs\UpdateCreatePriceIndex as UpdateCreatePriceIndexJob;

class Importer extends BaseImporter
{
    /**
     * Save products from current data
     */
    public function saveProductsData($data): bool
    {
        /**
         * Load SKU storage with data skus
         */
        $this->skuStorage->load(Arr::pluck($data, 'sku'));

        $products = [];

        $channels = [];

        $customerGroupPrices = [];

        $configurableVariants = [];

        $categories = [];

        $attributeValues = [];

        $inventories = [];

        $imagesData = [];

        $flatData = [];

        $links = [];

        foreach ($data as $rowData) {
            /**
             * change the product url key if already exist
             */
            $this->changeUrlKeyIfAlreadyExists($rowData, 1);
            /**
             * Prepare products for import
             */
            $this->prepareProducts($rowData, $products);

            /**
             * Prepare product channels to attach with products
             */
            $this->prepareChannels($rowData, $channels);

            /**
             * Prepare customer group prices
             */
            $this->prepareCustomerGroupPrices($rowData, $customerGroupPrices);

            /**
             * Prepare product categories to attach with products
             */
            $this->prepareCategories($rowData, $categories);

            /**
             * Prepare products attribute values
             */
            $this->prepareAttributeValues($rowData, $attributeValues);

            /**
             * Prepare products inventories for every inventory source
             */
            $this->prepareInventories($rowData, $inventories);

            /**
             * Prepare products images
             */
            $this->prepareImages($rowData, $imagesData);

            /**
             * Prepare products data for product_flat table
             */
            $this->prepareFlatData($rowData, $flatData);

            /**
             * Prepare products association for related, cross sell and up sell
             */
            $this->prepareLinks($rowData, $links);

            /**
             * Prepare configurable variants
             */
            $this->prepareConfigurableVariants($rowData, $configurableVariants);

        }

        $this->saveProducts($products);

        $this->saveChannels($channels);

        $this->saveCustomerGroupPrices($customerGroupPrices);

        $this->saveCategories($categories);

        $this->saveAttributeValues($attributeValues);

        $this->saveInventories($inventories);

        $this->saveImages($imagesData);

        $this->saveFlatData($flatData);

        $this->saveConfigurableVariants($configurableVariants);

        $this->saveLinks($links);

        return true;
    }

    /**
     * Change the url key if it already exists
     */
    public function changeUrlKeyIfAlreadyExists(array &$rowData, int $count, ?string $baseUrlKey = null): void
    {
        $baseUrlKey = $baseUrlKey ?? $rowData['url_key'];
        
        if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
            $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
        }

        $product = $this->productRepository
            ->setSearchEngine($searchEngine ?? 'database')
            ->findBySlug($rowData['url_key']);

        if (! empty($product['sku']) && $product['sku'] == $rowData['sku']) {
            return;
        }

        if ($product) {
            $rowData['url_key'] = $baseUrlKey.'-'.$count;
            $count++;
            $this->changeUrlKeyIfAlreadyExists($rowData, $count, $baseUrlKey);
        }
    }

    /**
     * Save products from current batch
     */
    public function prepareAttributeValues(array $rowData, array &$attributeValues): void
    {
        $data = [];

        $familyAttributes = $this->getProductTypeFamilyAttributes($rowData['type'], $rowData['attribute_family_code']);

        foreach ($rowData as $attributeCode => $value) {
            if (is_null($value)) {
                continue;
            }

            $attribute = $familyAttributes->where('code', $attributeCode)->first();
            
            if (! $attribute) {
                continue;
            }
            
            if ($attribute->type == 'select' && $attribute->is_configurable == '1') {
                // skip configurable attributes
                $attributeOption = $this->attributeOptionRepository
                    ->where('attribute_id', $attribute->id)
                    ->where('admin_name', $value)
                    ->first();

                $value = $attributeOption?->id ?? $value;
            }

            $attributeTypeValues = array_fill_keys(array_values($attribute->attributeTypeFields), null);

            $attributeValues[$rowData['sku']][] = array_merge($attributeTypeValues, [
                'attribute_id'          => $attribute->id,
                $attribute->column_name => $value,
                'channel'               => $attribute->value_per_channel ? $rowData['channel'] : null,
                'locale'                => $attribute->value_per_locale ? $rowData['locale'] : null,
            ]);
        }
    }

    /**
     * Prepare images from current batch
     */
    public function prepareImages(array $rowData, array &$imagesData): void
    {
        if (empty($rowData['images'])) {
            return;
        }

        /**
         * Reset the sku images data to prevent
         * data duplication in case of multiple locales
         */
        $imagesData[$rowData['sku']] = [];

        $imageNames = array_map('trim', explode(',', $rowData['images']));
        $disks = Config::get('filesystems.disks');

        foreach ($imageNames as $key => $image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                if (array_key_exists('s3', $disks) && !empty($disks['s3']['key'])) {
                    $parsedUrl = parse_url($image, PHP_URL_PATH);
                    $parsedUrl = ltrim($parsedUrl, '/');

                    if (Storage::disk('s3')->has($parsedUrl)) {
                        $path = Storage::disk('s3')->path($parsedUrl);
                        $productImage = $this->productImageRepository->where('path', $path)->first();
                        if ($productImage) {
                            continue;
                        }
                        $imagesData[$rowData['sku']][] = [
                            'name' => $parsedUrl,
                            'path' => $path,
                        ];

                        continue;
                    }
                } elseif (array_key_exists('azure', $disks) && !empty($disks['azure']['key'])) {
                    $parsedUrl = parse_url($image, PHP_URL_PATH);
                    $parsedUrl = ltrim($parsedUrl, '/');
                    $container = config('filesystems.disks.azure.container');

                    if (str_starts_with($parsedUrl, $container . '/')) {
                        $parsedUrl = substr($parsedUrl, strlen($container) + 1);
                    }

                    if (Storage::disk('azure')->has($parsedUrl)) {
                        $path = Storage::disk('azure')->path($parsedUrl);
                        $productImage = $this->productImageRepository->where('path', $path)->first();
                        if ($productImage) {
                            continue;
                        }

                        $imagesData[$rowData['sku']][] = [
                            'name' => $parsedUrl,
                            'path' => $path,
                        ];

                        continue;
                    }
                } else {
                    $imagePath = 'product'.DIRECTORY_SEPARATOR.$rowData['sku'];
                    $fullFilePath = $imagePath.'/'.basename($image);
                    $productImage = $this->productImageRepository->where('path', $fullFilePath)->first();
                    if ($productImage) {
                        continue;
                    }
                    if ($uploadedPath = $this->saveImageFromUrl($image, $imagePath)) {
                        $imagesData[$rowData['sku']][] = [
                            'name' => $uploadedPath,
                            'path' => Storage::path($uploadedPath),
                        ];

                        continue;
                    }
                }
            }
            if (! Storage::has($image)) {
                continue;
            }

            $imagesData[$rowData['sku']][] = [
                'name' => $image,
                'path' => Storage::path($image),
            ];
        }
    }

    /**
     * Save images from current batch
     */
    public function saveImages(array $imagesData): void
    {
        if (empty($imagesData)) {
            return;
        }
        $disks = Config::get('filesystems.disks');
        $productImages = [];
        foreach ($imagesData as $sku => $images) {
            $product = $this->skuStorage->get($sku);

            foreach ($images as $key => $image) {
                if (array_key_exists('s3', $disks) && !empty($disks['s3']['key'])) {
                    if (Storage::disk('s3')->has($image['name'])) {
                        $productImages[] = [
                            'type'       => 'images',
                            'path'       => $image['name'],
                            'product_id' => $product['id'],
                            'position'   => $key + 1,
                        ];
                    }
                } elseif (array_key_exists('azure', $disks) && !empty($disks['azure']['key'])) {
                    if (Storage::disk('azure')->has($image['name'])) {
                        $productImages[] = [
                            'type'       => 'images',
                            'path'       => $image['name'],
                            'product_id' => $product['id'],
                            'position'   => $key + 1,
                        ];
                    }
                } elseif (Storage::has($image['name'])) {
                    $productImages[] = [
                        'type'       => 'images',
                        'path'       => $image['name'],
                        'product_id' => $product['id'],
                        'position'   => $key + 1,
                    ];
                } else {
                    $file = new UploadedFile($image['path'], $image['name']);

                    $image = image_manager()->read($file)->encodeByExtension('webp');

                    $imageDirectory = $this->productImageRepository->getProductDirectory((object) $product);

                    $path = $imageDirectory.'/'.Str::random(40).'.webp';

                    $productImages[] = [
                        'type'       => 'images',
                        'path'       => $path,
                        'product_id' => $product['id'],
                        'position'   => $key + 1,
                    ];

                    Storage::put($path, $image);
                }
            }
        }

        $this->productImageRepository->insert($productImages);
    }

    protected function saveImageFromUrl(string $url, string $path, array $options = []): ?string
    {
        $response = Http::withOptions(['verify' => false])->get($url);

        if (! $response->successful()) {
            Log::error("Failed to fetch the image from URL: $url");

            return null;
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), 'url_image_');
        try {
            file_put_contents($tempFilePath, $response->body());
        } catch (\Exception $e) {
            Log::error("Unable to write temporary file for image URL: $url. Error: ".$e->getMessage());

            return null;
        }

        $image = image_manager()->read(file_get_contents($tempFilePath))->encodeByExtension('webp');

        $path = $path.'/'.basename($url);

        try {
            if (Storage::put($path, $image)) {
                return $path;
            }

            Log::error("Failed to store image from URL: $url to path: $path. Error: ");

            return null;
        } catch (\Exception $e) {
            Log::error("Failed to store image from URL: $url to path: $path. Error: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Prepare categories from current batch
     */
    public function prepareCategories(array $rowData, array &$categories): void
    {
        if (empty($rowData['categories'])) {
            return;
        }

        /**
         * Reset the sku categories data to prevent
         * data duplication in case of multiple locales
         */
        $categories[$rowData['sku']] = [];

        $rowCategoriesIds = explode('/', $rowData['categories'] ?? '');

        $categoryIds = [];

        foreach ($rowCategoriesIds as $rowCategoryId) {
            if (isset($this->categories[$rowCategoryId])) {
                $categoryIds = array_merge($categoryIds, $this->categories[$rowCategoryId]);

                continue;
            }

            $this->categories[$rowCategoryId] = $this->categoryRepository
                ->where('id', $rowCategoryId)
                ->pluck('id')
                ->toArray();

            $categoryIds = array_merge($categoryIds, $this->categories[$rowCategoryId]);
        }

        $categories[$rowData['sku']] = $categoryIds;
    }

    /**
     * Prepare inventories from current batch
     */
    public function prepareInventories(array $rowData, array &$inventories): void
    {
        if (empty($rowData['inventories'])) {
            return;
        }

        if ($this->skuStorage->has($rowData['sku'])) {
            return;
        }

        /**
         * Reset the sku inventories data to prevent
         * data duplication in case of multiple locales
         */
        $inventories[$rowData['sku']] = [];

        $inventorySources = explode(',', $rowData['inventories'] ?? '');

        foreach ($inventorySources as $inventorySource) {
            [$inventorySource, $qty] = explode('=', $inventorySource ?? '');

            $inventories[$rowData['sku']][] = [
                'source' => $inventorySource,
                'qty'    => $qty,
            ];
        }
    }

    /**
     * Prepare configurable variants
     */
    public function prepareConfigurableVariants(array $rowData, array &$configurableVariants): void
    {
        if (
            $rowData['type'] != self::PRODUCT_TYPE_CONFIGURABLE
            || empty($rowData['configurable_variants'])
        ) {
            return;
        }

        $variants = explode('|', $rowData['configurable_variants']);

        foreach ($variants as $variant) {
            parse_str(str_replace(',', '&', $variant), $variantAttributes);

            $configurableVariants[$rowData['sku']][$variantAttributes['sku']] = Arr::except($variantAttributes, 'sku');
        }
    }

    /**
     * Start the products indexing process
     */
    public function indexBatch($data): bool
    {
        /**
         * Load SKU storage with batch skus
         */
        $this->skuStorage->load(Arr::pluck($data, 'sku'));

        $typeProductIds = [];

        foreach ($data as $rowData) {
            $product = $this->skuStorage->get($rowData['sku']);

            $typeProductIds[$product['type']][] = (int) $product['id'];
        }

        $productIdsToIndex = [];

        foreach ($typeProductIds as $type => $productIds) {
            switch ($type) {
                case self::PRODUCT_TYPE_SIMPLE:
                case self::PRODUCT_TYPE_VIRTUAL:
                    $productIdsToIndex = [
                        ...$productIds,
                        ...$productIdsToIndex,
                    ];

                    /**
                     * Get all the parent bundle product ids
                     */
                    $parentBundleProductIds = $this->productBundleOptionRepository
                        ->select('product_bundle_options.product_id')
                        ->leftJoin('product_bundle_option_products', 'product_bundle_options.id', 'product_bundle_option_products.product_bundle_option_id')
                        ->whereIn('product_bundle_option_products.product_id', $productIds)
                        ->pluck('product_id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$parentBundleProductIds,
                    ];

                    /**
                     * Get all the parent grouped product ids
                     */
                    $parentGroupedProductIds = $this->productGroupedProductRepository
                        ->select('product_id')
                        ->whereIn('associated_product_id', $productIds)
                        ->pluck('product_id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$parentGroupedProductIds,
                    ];

                    /**
                     * Get all the parent configurable product ids
                     */
                    $parentConfigurableProductIds = $this->productRepository->select('parent_id')
                        ->whereIn('id', $productIds)
                        ->whereNotNull('parent_id')
                        ->pluck('parent_id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$parentConfigurableProductIds,
                    ];

                    break;

                case self::PRODUCT_TYPE_CONFIGURABLE:
                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$productIds,
                    ];

                    /**
                     * Get all configurable product children ids
                     */
                    $associatedProductIds = $this->productRepository->select('id')
                        ->whereIn('parent_id', $productIds)
                        ->pluck('id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$associatedProductIds,
                        ...$productIdsToIndex,
                    ];

                    break;

                case self::PRODUCT_TYPE_BUNDLE:
                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$productIds,
                    ];

                    /**
                     * Get all bundle product associated product ids
                     */
                    $associatedProductIds = $this->productBundleOptionProductRepository
                        ->select('product_bundle_option_products.product_id')
                        ->leftJoin('product_bundle_options', 'product_bundle_option_products.product_bundle_option_id', 'product_bundle_options.id')
                        ->whereIn('product_bundle_options.product_id', $productIds)
                        ->pluck('product_id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$associatedProductIds,
                        ...$productIdsToIndex,
                    ];

                    break;

                case self::PRODUCT_TYPE_GROUPED:
                    $productIdsToIndex = [
                        ...$productIdsToIndex,
                        ...$productIds,
                    ];

                    /**
                     * Get all grouped product associated product ids
                     */
                    $associatedProductIds = $this->productGroupedProductRepository
                        ->select('associated_product_id')
                        ->whereIn('product_id', $productIds)
                        ->pluck('associated_product_id')
                        ->toArray();

                    $productIdsToIndex = [
                        ...$associatedProductIds,
                        ...$productIdsToIndex,
                    ];

                    break;
            }
        }

        $productIdsToIndex = array_unique($productIdsToIndex);

        Bus::chain([
            new UpdateCreateInventoryIndexJob($productIdsToIndex),
            new UpdateCreatePriceIndexJob($productIdsToIndex),
            new UpdateCreateElasticSearchIndexJob($productIdsToIndex),
        ])->onConnection('sync')->dispatch();

        return true;
    }
}

