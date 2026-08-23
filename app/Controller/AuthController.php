<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
use App\Service\AuthChallengeService;
use App\Service\AuthSessionService;
use App\Service\LoginAttemptService;
use App\Service\OperationLogService;
use App\Service\PasswordPolicyService;
use App\Service\TotpService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * @Controller(prefix="/api")
 */
class AuthController extends AbstractController
{
    private OperationLogService $operationLogService;

    private LoginAttemptService $loginAttemptService;

    private PasswordPolicyService $passwordPolicyService;

    private TotpService $totpService;

    private AuthChallengeService $challengeService;

    private AuthSessionService $sessionService;

    public function __construct(
        OperationLogService $operationLogService,
        LoginAttemptService $loginAttemptService,
        PasswordPolicyService $passwordPolicyService,
        TotpService $totpService,
        AuthChallengeService $challengeService,
        AuthSessionService $sessionService,
        ResponseInterface $response
    ) {
        parent::__construct(null, null, $response);
        $this->operationLogService = $operationLogService;
        $this->loginAttemptService = $loginAttemptService;
        $this->passwordPolicyService = $passwordPolicyService;
        $this->totpService = $totpService;
        $this->challengeService = $challengeService;
        $this->sessionService = $sessionService;
    }

    /**
     * 用户登录。
     * @PostMapping(path="/login")
     */
    public function login(RequestInterface $request)
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            $this->recordLogin($username, null, $password, 'failed', '用户名或密码为空');
            return $this->response->json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        $lockStatus = $this->loginAttemptService->status($username);
        if ($lockStatus['locked']) {
            $this->recordLogin($username, null, $password, 'failed', '账号登录已锁定', [
                'remaining_seconds' => $lockStatus['remaining_seconds'],
            ]);
            return $this->response->json([
                'code' => 423,
                'msg' => sprintf('账号已锁定，请在%d分钟后重试', max(1, (int) ceil($lockStatus['remaining_seconds'] / 60))),
                'data' => ['remaining_seconds' => $lockStatus['remaining_seconds']],
            ]);
        }

        $user = User::query()->with('roles')->where('username', $username)->first();
        if (!$user || !password_verify($password, (string) $user->password)) {
            $failure = $this->loginAttemptService->registerFailure($username);
            $this->recordLogin($username, $user ? (int) $user->id : null, $password, 'failed', '用户名或密码错误', $failure);

            if ($failure['locked']) {
                return $this->response->json([
                    'code' => 423,
                    'msg' => sprintf('密码错误次数过多，账号已锁定%d分钟', (int) ceil($failure['remaining_seconds'] / 60)),
                    'data' => ['remaining_seconds' => $failure['remaining_seconds']],
                ]);
            }

            return $this->response->json([
                'code' => 401,
                'msg' => '用户名或密码错误',
                'data' => ['remaining_attempts' => max(0, 5 - (int) $failure['failure_count'])],
            ]);
        }

        $this->loginAttemptService->clear($username);

        if ($this->totpService->isVerificationEnabled()) {
            if ($user->isTotpRequired() && !$user->isTotpBound()) {
                $challengeToken = $this->challengeService->create((int) $user->id, 'login_bind');
                return $this->response->json([
                    'code' => 1001,
                    'msg' => '首次登录需要绑定身份验证器',
                    'data' => [
                        'two_factor_action' => 'bind',
                        'challenge_token' => $challengeToken,
                    ],
                ]);
            }

            if ($user->isTotpBound()) {
                $challengeToken = $this->challengeService->create((int) $user->id, 'login_verify');
                return $this->response->json([
                    'code' => 1002,
                    'msg' => '请输入身份验证器动态验证码',
                    'data' => [
                        'two_factor_action' => 'verify',
                        'challenge_token' => $challengeToken,
                    ],
                ]);
            }
        }

        return $this->completeLogin($user, '密码验证成功');
    }

    public function loginTotpSetup(RequestInterface $request)
    {
        $token = (string) $request->input('challenge_token', '');
        $challenge = $this->challengeService->get($token, 'login_bind');
        if (!$challenge) {
            return $this->response->json(['code' => 400, 'msg' => '绑定凭证已失效，请重新登录']);
        }

        $user = User::find((int) $challenge['user_id']);
        if (!$user || $user->isTotpBound()) {
            $this->challengeService->delete($token);
            return $this->response->json(['code' => 400, 'msg' => '当前账号无法执行首次绑定']);
        }

        $secret = $this->pendingSecret($token, $challenge);
        return $this->totpSetupResponse($user, $secret, $token);
    }

    public function loginTotpBind(RequestInterface $request)
    {
        $token = (string) $request->input('challenge_token', '');
        $code = (string) $request->input('code', '');
        $challenge = $this->challengeService->get($token, 'login_bind');
        if (!$challenge || empty($challenge['pending_secret'])) {
            return $this->response->json(['code' => 400, 'msg' => '绑定凭证已失效，请重新获取二维码']);
        }

        $user = User::find((int) $challenge['user_id']);
        if (!$user || $user->isTotpBound()) {
            $this->challengeService->delete($token);
            return $this->response->json(['code' => 400, 'msg' => '当前账号无法执行首次绑定']);
        }

        $secret = $this->totpService->decryptSecret((string) $challenge['pending_secret']);
        if (!$this->totpService->verify($secret, $code)) {
            $attempts = $this->challengeService->registerFailure($token, $challenge);
            return $this->response->json([
                'code' => 400,
                'msg' => $attempts >= 5 ? '验证码错误次数过多，请重新登录' : '动态验证码不正确',
            ]);
        }

        $this->bindTotpSecret($user, $secret);
        $this->challengeService->delete($token);
        $this->operationLogService->record(
            '用户安全',
            'bind_totp',
            'user',
            (string) $user->id,
            '首次绑定TOTP',
            [],
            'success',
            null,
            ['user_id' => (int) $user->id, 'username' => (string) $user->username]
        );

        return $this->completeLogin($user, '密码及TOTP绑定验证成功');
    }

    public function verifyLoginTotp(RequestInterface $request)
    {
        $token = (string) $request->input('challenge_token', '');
        $code = (string) $request->input('code', '');
        $challenge = $this->challengeService->get($token, 'login_verify');
        if (!$challenge) {
            return $this->response->json(['code' => 400, 'msg' => '登录验证已失效，请重新登录']);
        }

        $user = User::find((int) $challenge['user_id']);
        if (!$user || !$user->isTotpBound()) {
            $this->challengeService->delete($token);
            return $this->response->json(['code' => 400, 'msg' => '账号尚未绑定身份验证器']);
        }

        if (!$this->totpService->verifyEncryptedSecret((string) $user->totp_secret, $code)) {
            $attempts = $this->challengeService->registerFailure($token, $challenge);
            $this->operationLogService->record(
                '认证',
                'login_totp',
                'user',
                (string) $user->id,
                '登录TOTP验证失败',
                [],
                'failed',
                '动态验证码错误',
                ['user_id' => (int) $user->id, 'username' => (string) $user->username]
            );
            return $this->response->json([
                'code' => 401,
                'msg' => $attempts >= 5 ? '验证码错误次数过多，请重新登录' : '动态验证码不正确',
            ]);
        }

        $this->challengeService->delete($token);
        return $this->completeLogin($user, '密码及TOTP验证成功');
    }

    public function reauthenticate(RequestInterface $request)
    {
        $token = (string) $request->input('session_token', '');
        $code = (string) $request->input('code', '');

        try {
            $payload = $this->sessionService->decode($token);
        } catch (\Throwable $e) {
            return $this->response->json(['code' => 401, 'msg' => '会话凭证无效，请重新登录']);
        }

        if (!$this->sessionService->canReauthenticate($payload)) {
            return $this->response->json(['code' => 401, 'msg' => '会话已无法续期，请重新登录']);
        }

        $user = User::find((int) $payload->user_id);
        if (!$user || (int) ($payload->sv ?? 0) !== max(1, (int) ($user->session_version ?? 1))) {
            return $this->response->json(['code' => 401, 'msg' => '账号安全信息已变化，请重新登录']);
        }

        if (!$user->isTotpBound()) {
            return $this->response->json(['code' => 401, 'msg' => '当前账号未绑定身份验证器，请重新登录']);
        }

        if ($this->totpService->isVerificationEnabled()) {
            if (!$this->totpService->verifyEncryptedSecret((string) $user->totp_secret, $code)) {
                return $this->response->json(['code' => 401, 'msg' => '动态验证码不正确']);
            }
        }

        $session = $this->sessionService->create($user);
        $this->sessionService->revoke((string) ($payload->sid ?? ''));
        $this->operationLogService->record(
            '认证',
            'session_reauth',
            'user',
            (string) $user->id,
            '会话活动续期验证成功',
            [],
            'success',
            null,
            ['user_id' => (int) $user->id, 'username' => (string) $user->username]
        );

        return $this->response->json([
            'code' => 0,
            'msg' => '验证成功，会话已续期',
            'data' => ['token' => $session['token'], 'expires_in' => $session['expires_in']],
        ]);
    }

    /**
     * 获取用户信息。
     * @RequestMapping(path="/user/info", methods="get")
     */
    public function info(RequestInterface $request)
    {
        $userId = (int) $request->getAttribute('userId', 0);
        $user = $userId > 0 ? User::query()->with(['roles.permissions', 'town'])->find($userId) : null;
        if (!$user) {
            return $this->response->json(['code' => 401, 'msg' => '未登录或Token已失效']);
        }

        return $this->response->json(['code' => 0, 'msg' => '获取成功', 'data' => $user->toJwtArray()]);
    }

    public function securityInfo(RequestInterface $request)
    {
        $user = User::query()->with('roles')->find((int) $request->getAttribute('userId', 0));
        if (!$user) {
            return $this->response->json(['code' => 401, 'msg' => '未登录']);
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'totp_required' => $user->isTotpRequired(),
                'totp_bound' => $user->isTotpBound(),
                'can_bind' => !$user->isTotpBound(),
                'verification_bypassed' => !$this->totpService->isVerificationEnabled(),
            ],
        ]);
    }

    public function userTotpSetup(RequestInterface $request)
    {
        $user = User::find((int) $request->getAttribute('userId', 0));
        if (!$user || $user->isTotpBound()) {
            return $this->response->json(['code' => 400, 'msg' => '当前账号已绑定或无法绑定身份验证器']);
        }

        $token = $this->challengeService->create((int) $user->id, 'user_bind');
        $challenge = $this->challengeService->get($token, 'user_bind') ?? [];
        $secret = $this->pendingSecret($token, $challenge);
        return $this->totpSetupResponse($user, $secret, $token);
    }

    public function userTotpBind(RequestInterface $request)
    {
        $userId = (int) $request->getAttribute('userId', 0);
        $token = (string) $request->input('challenge_token', '');
        $code = (string) $request->input('code', '');
        $challenge = $this->challengeService->get($token, 'user_bind');
        if (!$challenge || (int) ($challenge['user_id'] ?? 0) !== $userId || empty($challenge['pending_secret'])) {
            return $this->response->json(['code' => 400, 'msg' => '绑定凭证已失效，请重新获取二维码']);
        }

        $user = User::find($userId);
        if (!$user || $user->isTotpBound()) {
            $this->challengeService->delete($token);
            return $this->response->json(['code' => 400, 'msg' => '当前账号已绑定或无法绑定身份验证器']);
        }

        $secret = $this->totpService->decryptSecret((string) $challenge['pending_secret']);
        if (!$this->totpService->verify($secret, $code)) {
            $attempts = $this->challengeService->registerFailure($token, $challenge);
            return $this->response->json([
                'code' => 400,
                'msg' => $attempts >= 5 ? '验证码错误次数过多，请重新获取二维码' : '动态验证码不正确',
            ]);
        }

        $this->bindTotpSecret($user, $secret);
        $this->challengeService->delete($token);
        $this->operationLogService->record('用户安全', 'bind_totp', 'user', (string) $user->id, '用户绑定TOTP');
        return $this->response->json(['code' => 0, 'msg' => '身份验证器绑定成功']);
    }

    /**
     * 修改密码。
     * @PostMapping(path="/user/change-password")
     */
    public function changePassword(RequestInterface $request)
    {
        $userId = (int) $request->getAttribute('userId', 0);
        $oldPassword = (string) $request->input('old_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $params = ['old_password' => $oldPassword, 'new_password' => $newPassword];

        if ($userId <= 0 || $oldPassword === '' || $newPassword === '') {
            return $this->response->json(['code' => 400, 'msg' => '原密码和新密码不能为空']);
        }

        $policyError = $this->passwordPolicyService->validate($newPassword);
        if ($policyError !== null) {
            $this->operationLogService->record('用户安全', 'change_password', 'user', (string) $userId, '修改密码失败', $params, 'failed', $policyError);
            return $this->response->json(['code' => 400, 'msg' => $policyError]);
        }

        $user = User::find($userId);
        if (!$user || !password_verify($oldPassword, (string) $user->password)) {
            $this->operationLogService->record('用户安全', 'change_password', 'user', (string) $userId, '修改密码失败', $params, 'failed', '原密码不正确');
            return $this->response->json(['code' => 400, 'msg' => '原密码不正确']);
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();
        $this->sessionService->revokeAll($user);
        $this->operationLogService->record('用户安全', 'change_password', 'user', (string) $userId, '修改密码成功', $params);

        return $this->response->json(['code' => 0, 'msg' => '密码修改成功，请重新登录']);
    }

    /**
     * 用户登出。
     * @PostMapping(path="/logout")
     */
    public function logout(RequestInterface $request)
    {
        $userId = (int) $request->getAttribute('userId', 0);
        $sid = (string) $request->getAttribute('sessionId', '');
        $this->sessionService->revoke($sid);
        $this->operationLogService->record('认证', 'logout', 'user', $userId > 0 ? (string) $userId : null, '用户退出登录');

        return $this->response->json(['code' => 0, 'msg' => '登出成功']);
    }

    private function pendingSecret(string $token, array $challenge): string
    {
        if (!empty($challenge['pending_secret'])) {
            return $this->totpService->decryptSecret((string) $challenge['pending_secret']);
        }

        $secret = $this->totpService->generateSecret();
        $challenge['pending_secret'] = $this->totpService->encryptSecret($secret);
        $this->challengeService->save($token, $challenge);
        return $secret;
    }

    private function bindTotpSecret(User $user, string $secret): void
    {
        $user->totp_secret = $this->totpService->encryptSecret($secret);
        $user->totp_bound_at = date('Y-m-d H:i:s');
        $user->totp_reset_at = null;
        $user->save();
        $this->sessionService->clearUserCache((int) $user->id);
    }

    private function totpSetupResponse(User $user, string $secret, string $challengeToken)
    {
        return $this->response->json([
            'code' => 0,
            'msg' => '请使用身份验证器扫描二维码并输入动态验证码',
            'data' => [
                'challenge_token' => $challengeToken,
                'secret' => $secret,
                'otpauth_uri' => $this->totpService->provisioningUri((string) $user->username, $secret),
            ],
        ]);
    }

    private function completeLogin(User $user, string $description)
    {
        $session = $this->sessionService->create($user);
        $this->recordLogin((string) $user->username, (int) $user->id, '', 'success', $description, [
            'session_expires_in' => $session['expires_in'],
        ]);

        return $this->response->json([
            'code' => 0,
            'msg' => '登录成功',
            'data' => [
                'token' => $session['token'],
                'expires_in' => $session['expires_in'],
                'user' => [
                    'id' => (int) $user->id,
                    'username' => (string) $user->username,
                    'nickname' => $user->nickname,
                ],
            ],
        ]);
    }

    private function recordLogin(
        string $username,
        ?int $userId,
        string $password,
        string $status,
        string $description,
        array $extra = []
    ): void {
        $params = array_merge(['username' => $username], $extra);
        if ($password !== '') {
            $params['password'] = $password;
        }
        $this->operationLogService->record(
            '认证',
            'login',
            'user',
            $userId === null ? null : (string) $userId,
            $description,
            $params,
            $status,
            $status === 'failed' ? $description : null,
            ['user_id' => $userId, 'username' => $username !== '' ? $username : null]
        );
    }
}
