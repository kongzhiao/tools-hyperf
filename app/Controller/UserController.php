<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
use App\Model\Role;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\Context\ApplicationContext;

/**
 * @Controller(prefix="/api/users")
 */
class UserController extends AbstractController
{
    /**
     * @Inject
     * @var Redis
     */
    protected $redis;

    /**
     * 用户列表
     * @RequestMapping(path="", methods="get")
     */
    public function index(RequestInterface $request)
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 10);
        $search = $request->input('search', '');

        $query = User::query()->with('roles')
            ->where('id', '!=', 1);

        if ($search) {
            $query->where('username', 'like', "%{$search}%")
                ->orWhere('nickname', 'like', "%{$search}%");
        }

        $total = $query->count();
        $users = $query->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'list' => $users,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * 创建用户
     * @RequestMapping(path="", methods="post")
     */
    public function store(RequestInterface $request)
    {
        $data = $request->all();

        // 验证必填字段
        if (empty($data['username']) || empty($data['password'])) {
            return $this->response->json([
                'code' => 400,
                'msg' => '用户名和密码不能为空'
            ]);
        }

        // 检查用户名是否已存在
        if (User::query()->where('username', $data['username'])->exists()) {
            return $this->response->json([
                'code' => 400,
                'msg' => '用户名已存在'
            ]);
        }

        // 加密密码
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $user = User::create($data);

        return $this->response->json([
            'code' => 0,
            'msg' => '创建成功',
            'data' => $user
        ]);
    }

    /**
     * 获取用户详情
     * @RequestMapping(path="/{id}", methods="get")
     */
    public function show($id)
    {
        $user = User::query()->with('roles')->find($id);

        if (!$user) {
            return $this->response->json([
                'code' => 404,
                'msg' => '用户不存在'
            ]);
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $user
        ]);
    }

    /**
     * 更新用户
     * @RequestMapping(path="/{id}", methods="put")
     */
    public function update($id, RequestInterface $request)
    {
        $user = User::find($id);

        if (!$user) {
            return $this->response->json([
                'code' => 404,
                'msg' => '用户不存在'
            ]);
        }

        $data = $request->all();

        // 如果更新密码，需要加密
        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        // 检查用户名是否重复（排除自己）
        if (!empty($data['username'])) {
            $exists = User::query()
                ->where('username', $data['username'])
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return $this->response->json([
                    'code' => 400,
                    'msg' => '用户名已存在'
                ]);
            }
        }

        $user->update($data);

        // 清除缓存
        try {
            $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
            $redis->del('user:cache:' . $id);
        } catch (\Exception $e) {
            error_log('Redis clear failed in update: ' . $e->getMessage());
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '更新成功',
            'data' => $user
        ]);
    }

    /**
     * 删除用户
     * @RequestMapping(path="/{id}", methods="delete")
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return $this->response->json([
                'code' => 404,
                'msg' => '用户不存在'
            ]);
        }

        $user->delete();

        // 清除缓存
        try {
            $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
            $redis->del('user:cache:' . $id);
        } catch (\Exception $e) {
            error_log('Redis clear failed in destroy: ' . $e->getMessage());
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '删除成功'
        ]);
    }

    /**
     * 分配角色
     * @RequestMapping(path="/{id}/roles", methods="post")
     */
    public function assignRoles($id, RequestInterface $request)
    {
        $user = User::find($id);

        if (!$user) {
            return $this->response->json([
                'code' => 404,
                'msg' => '用户不存在'
            ]);
        }

        $roleIds = $request->input('role_ids', []);

        // 同步用户角色
        $user->roles()->sync($roleIds);

        // 清除缓存
        try {
            $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
            $redis->del('user:cache:' . $id);
        } catch (\Exception $e) {
            error_log('Redis clear failed in assignRoles: ' . $e->getMessage());
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '角色分配成功'
        ]);
    }

    /**
     * 获取所有角色（用于分配）
     * @RequestMapping(path="/roles", methods="get")
     */
    public function getRoles()
    {
        $roles = Role::query()->where('id', '!=', 1)->get();

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $roles
        ]);
    }
}