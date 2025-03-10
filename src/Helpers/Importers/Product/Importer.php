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
use Intervention\Image\ImageManager;
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
     * Prepare images from current batch
     */
    public function prepareImages(array $rowData, array &$imagesData): void
    {
        if (empty($rowData['images'])) {
            return;
        }

        /**
         * Skip the image upload if product is already created
         */
        if ($this->skuStorage->has($rowData['sku'])) {
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
                if (array_key_exists('s3', $disks) && $disks['s3']['key'] !== null) {
                    $parsedUrl = parse_url($image, PHP_URL_PATH);
                    $parsedUrl = ltrim($parsedUrl, '/');

                    if (Storage::disk('s3')->has($parsedUrl)) {
                        $imagesData[$rowData['sku']][] = [
                            'name' => $parsedUrl,
                            'path' => Storage::disk('s3')->path($parsedUrl),
                        ];

                        continue;
                    }
                } else {
                    $imagePath = 'product'.DIRECTORY_SEPARATOR.$rowData['sku'];
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

                if (array_key_exists('s3', $disks) && $disks['s3']['key'] !== null) {
                    if (Storage::disk('s3')->has($image['name'])) {
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

                    $image = (new ImageManager)->make($file)->encode('webp');

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

    protected function saveImageFromUrl(string $url, string $path, array $options = []): string|null
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

        $image = (new ImageManager)->make(file_get_contents($tempFilePath))->encode('webp');

        $path = $path.'/'.Str::random(40).'.webp';

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
