<?php

namespace Webkul\RestApi\Helpers\Importers\Product;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\DataTransfer\Helpers\Importers\Product\Importer as BaseImporter;
use Illuminate\Support\Facades\Config;

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
		        } else if (Storage::has($image['name'])) {
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

    protected function saveImageFromUrl(string $url, string $path, array $options = []): string
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
            Log::error("Unable to write temporary file for image URL: $url. Error: " . $e->getMessage());

            return null;
        }

        $image = (new ImageManager)->make(file_get_contents($tempFilePath))->encode('webp');

        $path = $path.'/'.Str::random(40).'.webp';

        try {
            if (Storage::put($path, $image)) {
                return  $path;
            }

            Log::error("Failed to store image from URL: $url to path: $path. Error: ");

            return null;
        } catch (\Exception $e) {
            Log::error("Failed to store image from URL: $url to path: $path. Error: " . $e->getMessage());

            return null;
        }
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
}
