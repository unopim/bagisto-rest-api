<?php

namespace Webkul\RestApi\Http\Controllers\V1\Admin\Catalog;

use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Core\Rules\Code;
use Webkul\RestApi\Http\Resources\V1\Admin\Catalog\AttributeFamilyResource;
use Webkul\RestApi\Http\Resources\V1\Admin\Catalog\AttributeFamilyPayloadResource;

class AttributeFamilyController extends CatalogController
{
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
            'code'                      => ['required', 'unique:attribute_families,code', new Code],
            'name'                      => 'required',
            'attribute_groups.*.code'   => 'required',
            'attribute_groups.*.name'   => 'required',
            'attribute_groups.*.column' => 'required|in:1,2',
        ]);

        Event::dispatch('catalog.attribute_family.create.before');

        $this->normalizeDefaultFamily(request('code'));

        $attributeFamily = $this->getRepositoryInstance()->create([
            'attribute_groups'=> request('attribute_groups'),
            'code'            => request('code'),
            'name'            => request('name'),
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
            'code'                      => ['required', 'unique:attribute_families,code,'.$id, new Code],
            'name'                      => 'required',
            'attribute_groups.*.code'   => 'required',
            'attribute_groups.*.name'   => 'required',
            'attribute_groups.*.column' => 'required|in:1,2',
        ]);

        Event::dispatch('catalog.attribute_family.update.before', $id);

        $attributeFamily = $this->getRepositoryInstance()->findOrFail($id);

        if ($attributeFamily->code != request()->input('code')) {
            return response([
                'message' => trans('rest-api::app.admin.catalog.families.error.can-not-updated'),
            ], 400);
        }

        $this->normalizeDefaultFamily(request('code'));

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

    protected function normalizeDefaultFamily($code)
    {
        $attributeFamily = $this->getRepositoryInstance()->findOneByField('code', $code);
        if ($attributeFamily) {
            $response = $this->getResourceByCode($code);
        } else {
            $response = $this->getResourceByCode('default');
        }
        $defaultFamily = json_decode($response->getContent(), true)['data'];
        $defaultFamily['attribute_groups'] = $this->convertToPayload($defaultFamily['attribute_groups']);
        $this->updateBagistoFamilyPayload($defaultFamily, request()->all());
        $request = request();
        $request->merge($defaultFamily);
    }

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
            'attribute_groups' => array_values($attributeGroups), // Reindex groups
        ];
    }

    protected function updateBagistoFamilyPayload(&$defaultFamily, $unopimFamily)
    {
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

    public function convertToPayload($attributeGroups)
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
