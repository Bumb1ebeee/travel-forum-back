<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['token' => $token], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Неверные учетные данные'],
            ]);
        }

        \Log::info($user->is_blocked);

        // Проверяем, не заблокирован ли пользователь
        if ($user->is_blocked) {
            if ($user->blocked_until) {
                $blockedUntil = \Carbon\Carbon::parse($user->blocked_until);

                if (now()->lessThan($blockedUntil)) {
                    throw ValidationException::withMessages([
                        'login' => [
                            "Ваш аккаунт временно заблокирован. Разблокировка: " .
                            $blockedUntil->format('d.m.Y H:i')
                        ]
                    ]);
                } else {
                    // Разблокируем пользователя
                    $user->update([
                        'is_blocked' => false,
                        'blocked_until' => null,
                    ]);
                }
            } else {
                // Если нет даты, но is_blocked == true
                throw ValidationException::withMessages([
                    'login' => ['Ваш аккаунт заблокирован']
                ]);
            }
        }

        // Если 2FA включена — отправляем session_id
        if ($user->two_factor_enabled) {
            $sessionId = Str::random(40);
            cache()->put('2fa:session:' . $sessionId, $user->id, now()->addMinutes(10));
            return response()->json([
                'two_factor_enabled' => true,
                'session_id' => $sessionId,
            ]);
        }

        // Создаём токен и возвращаем
        $token = $user->createToken('auth_token')->plainTextToken;
        return response()->json(['token' => $token]);
    }

    public function verify2FA(Request $request)
    {
        $request->validate([
            'two_factor_code' => 'required|string',
            'session_id' => 'required|string',
        ]);

        // Проверяем сессию
        $userId = cache()->get('2fa:session:' . $request->session_id);
        if (!$userId) {
            \Log::info('Недействительный или истёкший session_id:', ['session_id' => $request->session_id]);
            throw ValidationException::withMessages([
                'session_id' => ['Недействительная сессия или время истекло'],
            ]);
        }

        $user = User::find($userId);
        if (!$user) {
            \Log::info('Пользователь не найден для session_id:', ['session_id' => $request->session_id]);
            throw ValidationException::withMessages([
                'session_id' => ['Пользователь не найден'],
            ]);
        }

        if (!$user->two_factor_enabled) {
            \Log::info('2FA не включена для:', ['user_id' => $user->id]);
            throw ValidationException::withMessages([
                'two_factor_code' => ['2FA не включена для этого пользователя'],
            ]);
        }

        $valid = $this->google2fa->verifyKey($user->two_factor_secret, $request->two_factor_code);

        if (!$valid) {
            \Log::info('Неверный TOTP-код для:', ['user_id' => $user->id]);
            throw ValidationException::withMessages([
                'two_factor_code' => ['Неверный код двухфакторной аутентификации'],
            ]);
        }

        // Удаляем сессию и выдаём токен
        cache()->forget('2fa:session:' . $request->session_id);
        $token = $user->createToken('auth_token')->plainTextToken;
        \Log::info('Успешный вход с 2FA для:', ['user_id' => $user->id]);
        return response()->json(['token' => $token]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function checkToken(Request $request)
    {
        if ($request->user()) {
            return response()->json(['authenticated' => true], 200);
        }
        return response()->json(['authenticated' => false], 401);
    }

    public function setup2FA(Request $request)
    {
        $user = $request->user();
        if ($user->two_factor_enabled) {
            return response()->json(['message' => '2FA уже включена'], 400);
        }

        $secret = $this->google2fa->generateSecretKey();
        $user->two_factor_secret = $secret;
        $user->save();

        $otpauthUrl = $this->google2fa->getQRCodeUrl(
            'MyApp',
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new ImagickImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($otpauthUrl);

        return response()->json([
            'qr_code' => base64_encode($qrCode),
            'secret' => $secret,
        ]);
    }

    public function enable2FA(Request $request)
    {
        $request->validate([
            'two_factor_code' => 'required|string',
        ]);

        $user = $request->user();
        if ($user->two_factor_enabled) {
            return response()->json(['message' => '2FA уже включена'], 400);
        }

        $valid = $this->google2fa->verifyKey($user->two_factor_secret, $request->two_factor_code);

        if (!$valid) {
            throw ValidationException::withMessages([
                'two_factor_code' => ['Неверный код'],
            ]);
        }

        $user->two_factor_enabled = true;
        $user->save();

        return response()->json(['message' => '2FA успешно включена']);
    }

    public function disable2FA(Request $request)
    {
        $user = $request->user();
        $user->two_factor_enabled = false;
        $user->two_factor_secret = null;
        $user->save();

        return response()->json(['message' => '2FA отключена']);
    }
}
