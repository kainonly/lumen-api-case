<?php
declare(strict_types=1);

namespace App\Controller\System;

use App\RedisModel\System\ResourceRedis;
use App\RedisModel\System\RoleRedis;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class ResourceController extends BaseController
{
    /**
     * @Inject()
     * @var ResourceRedis
     */
    private ResourceRedis $resourceRedis;
    /**
     * @Inject()
     * @var RoleRedis
     */
    private RoleRedis $roleRedis;

    public function originLists(): array
    {
        $validate = $this->curd->originListsValidation([]);
        if ($validate['error'] === 1) {
            return $validate;
        }
        return $this->curd
            ->originListsModel('resource')
            ->setOrder('sort', 'asc')
            ->result();
    }

    public function add(): array
    {
        $validate = $this->curd->addValidation([
            'key' => 'required',
            'name' => 'required|json'
        ]);
        if ($validate['error'] === 1) {
            return $validate;
        }
        return $this->curd
            ->addModel('resource')
            ->onAfterEvent(function () {
                $this->clearRedis();
            })
            ->result();
    }

    public function edit(): array
    {
        $body = $this->request->post();
        $validate = $this->curd->editValidation([
            'key' => 'required',
            'name' => 'required|json'
        ]);
        if ($validate['error'] === 1) {
            return $validate;
        }
        $key = null;
        if (!$body['switch']) {
            $data = Db::table('resource')
                ->where('id', '=', $body['id'])
                ->first();

            if (!empty($data)) {
                $key = $data->key;
            }
        }
        return $this->curd
            ->editModel('resource', $body)
            ->onAfterEvent(function (int $id, bool $switch) use ($body, $key) {
                if (!$switch && $body['key'] !== $key) {
                    Db::table('resource')
                        ->where('parent', '=', $key)
                        ->update([
                            'parent' => $body['key']
                        ]);
                }
                $this->clearRedis();
            })
            ->result();
    }

    public function delete(): array
    {
        $body = $this->request->post();
        $validate = $this->curd->deleteValidation([]);
        if ($validate['error'] === 1) {
            return $validate;
        }
        $data = Db::table('resource')
            ->whereIn('id', $body['id'])
            ->first();

        if (empty($data)) {
            return [
                'error' => 1,
                'msg' => 'not exist'
            ];
        }

        $exists = Db::table('resource')
            ->where('parent', '=', $data->key)
            ->exists();

        if ($exists) {
            return [
                'error' => 1,
                'msg' => 'has child'
            ];
        }

        return $this->curd
            ->deleteModel('resource', $body)
            ->onAfterEvent(function () {
                $this->clearRedis();
            })
            ->result();
    }

    /**
     * Sort Lists
     * @return array
     */
    public function sort(): array
    {
        $body = $this->request->post();
        $validator = $this->validation->make($body, [
            'data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return [
                'error' => 1,
                'msg' => $validator->errors()
            ];
        }

        return Db::transaction(function () use ($body) {
            foreach ($body['data'] as $value) {
                Db::table('resource')
                    ->where('id', '=', $value['id'])
                    ->update([
                        'sort' => $value['sort']
                    ]);
            }
            $this->clearRedis();
            return true;
        }) ? [
            'error' => 0,
            'msg' => 'success'
        ] : [
            'error' => 1,
            'msg' => 'error'
        ];
    }

    private function clearRedis(): void
    {
        $this->resourceRedis->clear();
        $this->roleRedis->clear();
    }

    /**
     * Exists Resources Key
     * @return array
     */
    public function validedKey(): array
    {
        $body = $this->request->post();
        if (empty($body['key'])) {
            return [
                'error' => 1,
                'msg' => 'error:require_key'
            ];
        }

        $exists = Db::table('resource')
            ->where('key', '=', $body['key'])
            ->exists();

        return [
            'error' => 0,
            'data' => $exists
        ];
    }
}