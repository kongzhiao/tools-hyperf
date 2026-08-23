<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Role;
use App\Model\User;
use App\Service\AuthSessionService;
use App\Service\LoginAttemptService;
use App\Service\OperationLogService;
use App\Service\PasswordPolicyService;
use App\Service\TotpService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * @Controller(prefix="/api/users")
 */
class UserController extends AbstractController
{
    private PasswordPolicyService $passwordPolicyService;

    private OperationLogService $operationLogService;

    private LoginAttemptService $loginAttemptService;

    private AuthSessionService $sessionService;

    private TotpService $totpService;

    public function __construct(
        PasswordPolicyService $passwordPolicyService,
        OperationLogService $operationLogService,
        LoginAttemptService $loginAttemptService,
        AuthSessionService $sessionService,
        TotpService $totpService,
        ResponseInterface $response
    ) {
        parent::__construct(null, null, $response);
        $this->passwordPolicyService = $passwordPolicyService;
        $this->operationLogService = $operationLogService;
        $this->loginAttemptService = $loginAttemptService;
        $this->sessionService = $sessionService;
        $this->totpService = $totpService;
    }

    /** @RequestMapping(path="", methods="get") */
    public function index(RequestInterface $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 10)));
        $search = trim((string) $request->input('search', $request->input('keyword', '')));
        $roleId = $request->input('role_id');
        $townId = $request->input('town_id');

        $query = User::query()->with(['roles', 'town'])->where('id', '!=', 1);
        if ($search !== '') {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('username', 'like', "%{$search}%")
                    ->orWhere('nickname', 'like', "%{$search}%");
            });
        }
        if ($roleId !== null && $roleId !== '') {
            $query->whereHas('roles', function ($subQuery) use ($roleId) {
                $subQuery->where('roles.id', (int) $roleId);
            });
        }
        if ($townId !== null && $townId !== '') {
            if ((string) $townId === '0') {
                $query->where(function ($subQuery) {
                    $subQuery->whereNull('town_id')->orWhere('town_id', 0);
                });
            } else {
                $query->where('town_id', (int) $townId);
            }
        }

        $total = $query->count();
        $users = $query->offset(($page - 1) * $limit)->limit($limit)->get();
        foreach ($users as $user) {
            $this->appendSecurityState($user);
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => ['list' => $users, 'total' => $total, 'page' => $page, 'limit' => $limit],
        ]);
    }

    /** @RequestMapping(path="", methods="post") */
    public function store(RequestInterface $request)
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            return $this->response->json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }
        if (User::query()->where('username', $username)->exists()) {
            return $this->response->json(['code' => 400, 'msg' => '用户名已存在']);
        }

        $policyError = $this->passwordPolicyService->validate($password);
        if ($policyError !== null) {
            return $this->response->json(['code' => 400, 'msg' => $policyError]);
        }

        $actor = $this->currentUser($request);
        $canManageSecurity = $actor?->hasAdminCapability() ?? false;
        $data = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $request->input('nickname'),
            'town_id' => $this->normalizeTownId($request->input('town_id')),
            'totp_required' => $canManageSecurity
                ? $this->booleanInput($request->input('totp_required', true), true)
                : true,
            'session_version' => 1,
        ];
        $user = User::create($data);

        $this->operationLogService->record(
            '用户管理',
            'create_user',
            'user',
            (string) $user->id,
            '创建系统用户',
            [
                'username' => $username,
                'password' => $password,
                'totp_required' => $data['totp_required'],
            ]
        );

        $this->appendSecurityState($user);
        return $this->response->json(['code' => 0, 'msg' => '创建成功', 'data' => $user]);
    }

    /** @RequestMapping(path="/{id}", methods="get") */
    public function show($id)
    {
        $user = User::query()->with(['roles', 'town'])->find((int) $id);
        if (!$user) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }
        $this->appendSecurityState($user);
        return $this->response->json(['code' => 0, 'msg' => '获取成功', 'data' => $user]);
    }

    /** @RequestMapping(path="/{id}", methods="put") */
    public function update($id, RequestInterface $request)
    {
        $user = User::query()->with('roles')->find((int) $id);
        if (!$user) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }

        $data = [];
        $input = $request->all();
        if (array_key_exists('username', $input)) {
            $username = trim((string) $request->input('username'));
            if ($username === '') {
                return $this->response->json(['code' => 400, 'msg' => '用户名不能为空']);
            }
            $exists = User::query()->where('username', $username)->where('id', '!=', (int) $id)->exists();
            if ($exists) {
                return $this->response->json(['code' => 400, 'msg' => '用户名已存在']);
            }
            $data['username'] = $username;
        }
        if (array_key_exists('nickname', $input)) {
            $data['nickname'] = $request->input('nickname');
        }
        if (array_key_exists('town_id', $input)) {
            $data['town_id'] = $this->normalizeTownId($request->input('town_id'));
        }

        if (array_key_exists('totp_required', $input)) {
            $actor = $this->currentUser($request);
            if (!$actor || !$actor->hasAdminCapability()) {
                return $this->response->json(['code' => 403, 'msg' => '仅超级管理员或管理员角色可配置2FA要求']);
            }
            $data['totp_required'] = $user->hasAdminCapability()
                ? true
                : $this->booleanInput($request->input('totp_required'), false);
        }

        $plainPassword = (string) $request->input('password', '');
        $passwordChanged = $plainPassword !== '';
        if ($passwordChanged) {
            $policyError = $this->passwordPolicyService->validate($plainPassword);
            if ($policyError !== null) {
                return $this->response->json(['code' => 400, 'msg' => $policyError]);
            }
            $data['password'] = password_hash($plainPassword, PASSWORD_DEFAULT);
        }

        $user->update($data);
        if ($passwordChanged) {
            $this->sessionService->revokeAll($user);
        } else {
            $this->sessionService->clearUserCache((int) $user->id);
        }

        $this->operationLogService->record(
            '用户管理',
            'update_user',
            'user',
            (string) $user->id,
            '更新系统用户',
            array_filter([
                'username' => $data['username'] ?? null,
                'password' => $passwordChanged ? $plainPassword : null,
                'totp_required' => $data['totp_required'] ?? null,
                'password_changed' => $passwordChanged,
            ], static fn ($value) => $value !== null)
        );

        $user->load(['roles', 'town']);
        $this->appendSecurityState($user);
        return $this->response->json(['code' => 0, 'msg' => '更新成功', 'data' => $user]);
    }

    /** @RequestMapping(path="/{id}", methods="delete") */
    public function destroy($id)
    {
        $user = User::find((int) $id);
        if (!$user) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }
        if ((int) $user->id === 1) {
            return $this->response->json(['code' => 403, 'msg' => '超级管理员账号不能删除']);
        }

        $targetId = (int) $user->id;
        $targetUsername = (string) $user->username;
        $user->delete();
        $this->sessionService->clearUserCache($targetId);
        $this->loginAttemptService->clear($targetUsername);
        $this->operationLogService->record('用户管理', 'delete_user', 'user', (string) $targetId, '删除系统用户', [
            'username' => $targetUsername,
        ]);

        return $this->response->json(['code' => 0, 'msg' => '删除成功']);
    }

    /** @RequestMapping(path="/{id}/roles", methods="post") */
    public function assignRoles($id, RequestInterface $request)
    {
        $user = User::query()->with('roles')->find((int) $id);
        if (!$user) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }

        $roleIds = array_values(array_unique(array_map('intval', (array) $request->input('role_ids', []))));
        $administratorRole = Role::query()->where('name', '管理员')->first();
        $hadAdministratorRole = $user->roles->contains(
            static fn (Role $role) => (string) $role->name === '管理员'
        );
        $willHaveAdministratorRole = $administratorRole
            ? in_array((int) $administratorRole->id, $roleIds, true)
            : false;
        if ($hadAdministratorRole !== $willHaveAdministratorRole) {
            $actor = $this->currentUser($request);
            if (!$actor || !$actor->hasAdminCapability()) {
                return $this->response->json(['code' => 403, 'msg' => '仅超级管理员或管理员角色可授予或移除管理员角色']);
            }
        }

        $user->roles()->sync($roleIds);
        $isAdministrator = $willHaveAdministratorRole;
        if ($isAdministrator && !$user->totp_required) {
            $user->totp_required = true;
            $user->save();
        }
        $this->sessionService->revokeAll($user);

        $this->operationLogService->record('用户管理', 'assign_roles', 'user', (string) $user->id, '分配用户角色', [
            'role_ids' => $roleIds,
        ]);
        return $this->response->json(['code' => 0, 'msg' => '角色分配成功']);
    }

    /** @RequestMapping(path="/{id}/totp/reset", methods="post") */
    public function resetTotp($id, RequestInterface $request)
    {
        $actor = $this->currentUser($request);
        $target = User::find((int) $id);
        if (!$actor || !$actor->hasAdminCapability()) {
            return $this->response->json(['code' => 403, 'msg' => '仅超级管理员或管理员角色可重置2FA']);
        }
        if (!$target) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }
        if ((int) $actor->id === (int) $target->id) {
            return $this->response->json(['code' => 400, 'msg' => '不能重置自己的2FA']);
        }

        $totpCode = (string) $request->input('totp_code', '');
        if ($this->totpService->isVerificationEnabled()) {
            if (!$actor->isTotpBound() || !$this->totpService->verifyEncryptedSecret((string) $actor->totp_secret, $totpCode)) {
                return $this->response->json(['code' => 400, 'msg' => '当前操作人的动态验证码不正确']);
            }
        }

        $target->totp_secret = null;
        $target->totp_bound_at = null;
        $target->totp_reset_at = date('Y-m-d H:i:s');
        $target->save();
        $this->sessionService->revokeAll($target);
        $this->operationLogService->record('用户安全', 'reset_totp', 'user', (string) $target->id, '管理员重置用户TOTP', [
            'target_username' => (string) $target->username,
        ]);

        return $this->response->json(['code' => 0, 'msg' => '2FA已重置，用户下次登录需要重新绑定']);
    }

    /** @RequestMapping(path="/{id}/login-lock", methods="delete") */
    public function clearLoginLock($id, RequestInterface $request)
    {
        $actor = $this->currentUser($request);
        $target = User::find((int) $id);
        if (!$actor || !$actor->hasAdminCapability()) {
            return $this->response->json(['code' => 403, 'msg' => '仅超级管理员或管理员角色可解除登录锁定']);
        }
        if (!$target) {
            return $this->response->json(['code' => 404, 'msg' => '用户不存在']);
        }

        $this->loginAttemptService->clear((string) $target->username);
        $this->operationLogService->record('用户安全', 'clear_login_lock', 'user', (string) $target->id, '解除账号登录锁定', [
            'target_username' => (string) $target->username,
        ]);
        return $this->response->json(['code' => 0, 'msg' => '登录锁定已解除']);
    }

    /** @RequestMapping(path="/roles", methods="get") */
    public function getRoles()
    {
        $roles = Role::query()->where('id', '!=', 1)->get();
        return $this->response->json(['code' => 0, 'msg' => '获取成功', 'data' => $roles]);
    }

    private function currentUser(RequestInterface $request): ?User
    {
        $userId = (int) $request->getAttribute('userId', 0);
        return $userId > 0 ? User::query()->with('roles')->find($userId) : null;
    }

    private function appendSecurityState(User $user): void
    {
        $user->setAttribute('totp_effective_required', $user->isTotpRequired());
        $user->setAttribute('totp_bound', $user->isTotpBound());
        $lock = $this->loginAttemptService->status((string) $user->username);
        $user->setAttribute('login_locked', (bool) $lock['locked']);
        $user->setAttribute('login_lock_remaining_seconds', (int) $lock['remaining_seconds']);
    }

    private function normalizeTownId($value): ?int
    {
        return empty($value) ? null : (int) $value;
    }

    private function booleanInput($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
