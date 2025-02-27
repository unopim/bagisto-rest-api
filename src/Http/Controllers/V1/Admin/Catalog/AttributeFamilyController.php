<?php

namespace Webkul\RestApi\Http\Controllers\V1\Admin\Catalog;

use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Core\Rules\Code;
use Webkul\RestApi\Http\Resources\V1\Admin\Catalog\AttributeFamilyPayloadResource;
use Webkul\RestApi\Http\Resources\V1\Admin\Catalog\AttributeFamilyResource;

class AttributeFamilyController extends CatalogController
{
    const DEFAULT_FAMILY_CODE = 'default';

    /**
     * Repository class name.
     */
    public function repository(): string
    {
        return AttributeFamilyRepository::class;
    }

    /**
     * Resource class name.
     */
    public function resource(): string
    {
        return AttributeFamilyResource::class;
    }

    /**
     * Resource class name.
     */
    public function getResourceByCode(string $code)
    {
        return response([
            'data' => new AttributeFamilyPayloadResource($this->getRepositoryInstance()->findOneByField('code', $code)),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $this->validate(request(), [
            'code'                                        => ['required', 'unique:attribute_families,code', new Code],
            'name'                                        => 'required',
            'attribute_groups.*.code'                     => 'required',
            'attribute_groups.*.name'                     => 'required',
            'attribute_groups.*.column'                   => 'required|in:1,2',
            'attribute_groups.*.custom_attributes.*.code' => 'required',
        ]);

        Event::dispatch('catalog.attribute_family.create.before');

        $this->mergeRequestWithDefaultFamily(request('code'));

        $attributeFamily = $this->getRepositoryInstance()->create([
            'attribute_groups' => request('attribute_groups'),
            'code'             => request('code'),
            'name'             => request('name'),
        ]);

        Event::dispatch('catalog.attribute_family.create.after', $attributeFamily);

        return response([
            'data'    => new AttributeFamilyResource($attributeFamily),
            'message' => trans('rest-api::app.admin.catalog.families.create-success'),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(int $id)
    {
        $this->validate(request(), [
            'code'                                        => ['required', 'unique:attribute_families,code,'.$id, new Code],
            'name'                                        => 'required',
            'attribute_groups.*.code'                     => 'required',
            'attribute_groups.*.name'                     => 'required',
            'attribute_groups.*.column'                   => 'required|in:1,2',
            'attribute_groups.*.custom_attributes.*.code' => 'required',
        ]);

        Event::dispatch('catalog.attribute_family.update.before', $id);

        $attributeFamily = $this->getRepositoryInstance()->findOrFail($id);

        if ($attributeFamily->code != request()->input('code')) {
            return response([
                'message' => trans('rest-api::app.admin.catalog.families.error.can-not-updated'),
            ], 400);
        }

        $this->mergeRequestWithDefaultFamily(request('code'));

        $attributeFamily = $this->getRepositoryInstance()->update(request()->only([
            'attribute_groups',
            'name',
            'code',
        ]), $id);

        Event::dispatch('catalog.attribute_family.update.after', $attributeFamily);

        return response([
            'data'    => new AttributeFamilyResource($attributeFamily),
            'message' => trans('rest-api::app.admin.catalog.families.update-success'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(int $id)
    {
        $attributeFamily = $this->getRepositoryInstance()->findOrFail($id);

        if ($this->getRepositoryInstance()->count() == 1) {
            return response([
                'message' => trans('rest-api::app.admin.catalog.families.error.last-item-delete'),
            ], 400);
        }

        if ($attributeFamily->products()->count()) {
            return response([
                'message' => trans('rest-api::app.admin.catalog.families.error.being-used'),
            ], 400);
        }

        Event::dispatch('catalog.attribute_family.delete.before', $id);

        $attributeFamily->delete();

        Event::dispatch('catalog.attribute_family.delete.after', $id);

        return response([
            'message' => trans('rest-api::app.admin.catalog.families.delete-success'),
        ]);
    }

    /**
     * Merges the current request data with the default family attributes.
     *
     * @param  string  $code  The code used to retrieve the default family attributes.
     */
    protected function mergeRequestWithDefaultFamily(string $code): void
    {
        $defaultFamily = $this->normalizeDefaultFamily($code);
        $this->updateBagistoFamilyPayload($defaultFamily, request()->all());
        $request = request();
        $request->merge($defaultFamily);
    }

    /**
     * Normalize default family data.
     *
     * @param  string  $code  The code of the attribute family.
     */
    protected function normalizeDefaultFamily(string $code): array
    {
        $attributeFamily = $this->getRepositoryInstance()->findOneByField('code', $code);
        $response = $attributeFamily ? $this->getResourceByCode($code) : $this->getResourceByCode(self::DEFAULT_FAMILY_CODE);

        $defaultFamily = [];
        if (! empty($response->getContent())) {
            $defaultFamily = json_decode($response->getContent(), true)['data'];
            $defaultFamily['attribute_groups'] = $this->convertToPayload($defaultFamily['attribute_groups']);

            return $defaultFamily;
        }

        return $defaultFamily;
    }

    /**
     * Merge the attribute families by combining the base array with the merge array.
     *
     * @param  array  $baseArray  The base attribute family array.
     * @param  array  $mergeArray  The attribute family array to merge into the base array.
     * @return array The merged attribute family array.
     */
    protected function mergeAttributeFamilies(array $baseArray, array $mergeArray): array
    {
        $attributeGroups = [];

        foreach ($mergeArray['attribute_groups'] as $group) {
            $attributeGroups[$group['code']] = $group;
        }

        foreach ($baseArray['attribute_groups'] as $groupKey => $group) {
            $code = $group['code'];

            if (isset($attributeGroups[$code])) {
                $existingAttributes = array_column($attributeGroups[$code]['custom_attributes'], null, 'id');

                foreach ($group['custom_attributes'] as $attribute) {
                    $existingAttributes[$attribute['id']] = $attribute;
                }

                $attributeGroups[$code]['custom_attributes'] = array_values($existingAttributes);
            } else {
                $attributeGroups[$code] = $group;
            }
        }

        return [
            'code'             => $mergeArray['code'],
            'name'             => $mergeArray['name'],
            'id'               => $mergeArray['id'],
            'attribute_groups' => array_values($attributeGroups),
        ];
    }

    /**
     * Update the default attribute family payload with the provided family data.
     *
     * @param  array  $defaultFamily  The default attribute family data to be updated.
     * @param  array  $unopimFamily  The new attribute family data to merge into the default family.
     */
    protected function updateBagistoFamilyPayload(array &$defaultFamily, array $unopimFamily): void
    {
        if (! $defaultFamily) {
            return;
        }
        $defaultFamily['code'] = $unopimFamily['code'];
        $defaultFamily['name'] = $unopimFamily['name'];
        unset($defaultFamily['id']);
        foreach ($unopimFamily['attribute_groups'] as $groupId => $group) {
            $existingGroupKey = null;
            foreach ($defaultFamily['attribute_groups'] as $key => $existingGroup) {
                if ($existingGroup['code'] === $group['code']) {
                    $existingGroupKey = $key;
                    break;
                }
            }

            if ($existingGroupKey === null) {
                $defaultFamily['attribute_groups'][$groupId] = $group;
            } else {
                foreach ($group['custom_attributes'] as $newAttribute) {
                    $exists = false;
                    foreach ($defaultFamily['attribute_groups'][$existingGroupKey]['custom_attributes'] as $existingAttribute) {
                        if ($existingAttribute['code'] === $newAttribute['code']) {
                            $exists = true;
                            break;
                        }
                    }

                    if (! $exists) {
                        $defaultFamily['attribute_groups'][$existingGroupKey]['custom_attributes'][] = $newAttribute;
                    }
                }
            }
        }
    }

    /**
     * Convert attribute groups to payload format.
     */
    public function convertToPayload(array $attributeGroups): array
    {
        $transformedArray = [];

        foreach ($attributeGroups as $group) {
            $id = $group['id'];
            unset($group['id']);
            $transformedArray[$id] = $group;
        }

        return $transformedArray;
    }
}
