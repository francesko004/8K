<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\SpinRuns;
use App\Models\Setting;
use App\Models\Wallet;
use App\Traits\Affiliates\AffiliateHistoryTrait;
use App\Http\Controllers\Controller;
use App\Models\AffiliateHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use AffiliateHistoryTrait;

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth.jwt', ['except' => ['login', 'register', 'submitForgetPassword', 'submitResetPassword']]);
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login()
    {
        try {
            $credentials = request(['email', 'password']);

            if (!$token = auth('api')->attempt($credentials)) {
                return response()->json(['error' => trans('Check credentials')], 400);
            }

            return $this->respondWithToken($token);
        } catch (JWTException $e) {
            return response()->json([
                'error' => 'Could not create token'
            ], 400);
        }
    }

    /**
     * Register a new user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            $setting = \Helper::getSetting();

            $rules = [
                'cpf'          => 'required|string',
                'phone'        => 'required|string',
                'email'        => 'required|email|unique:users',
                'password'     => ['required', Rules\Password::min(6)],
                'term_a'       => 'required',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }

            $userData = $request->only(['cpf', 'phone', 'password', 'email']);
            $userData['name'] = $request->email;  // Usar um nome padrão ou adicionar campo no formulário

            if ($user = User::create($userData)) {
                if (isset($request->reference_code) && !empty($request->reference_code)) {
                    $checkAffiliate = User::where('inviter_code', $request->reference_code)->first();
                    if (!empty($checkAffiliate)) {
                        $user->update(['inviter' => $checkAffiliate->id]);
                        self::saveAffiliateHistory($user);
                    }
                }
                $this->createWallet($user);

                if ($setting->disable_spin) {
                    if (!empty($request->spin_token)) {
                        try {
                            $str = base64_decode($request->spin_token);
                            $obj = json_decode($str);

                            $spin_run = SpinRuns::where([
                                'key'   => $obj->signature,
                                'nonce' => $obj->nonce
                            ])->first();

                            $data = $spin_run->prize;
                            $obj = json_decode($data);
                            $value = $obj->value;

                            Wallet::where('user_id', $user->id)->increment('balance_bonus', $value);
                        } catch (\Exception $e) {
                            return response()->json(['error' => $e->getMessage()], 400);
                        }
                    }
                }

                $credentials = $request->only(['email', 'password']);
                $token = auth('api')->attempt($credentials);
                if (!$token) {
                    return response()->json(['error' => 'Unauthorized'], 401);
                }

                return $this->respondWithToken($token);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * @param $user
     * @return void
     */
    private function createWallet($user)
    {
        $setting = \Helper::getSetting();

        Wallet::create([
            'user_id'   => $user->id,
            'currency'  => $setting->currency_code,
            'symbol'    => $setting->prefix,
            'active'    => 1
        ]);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return response()->json(auth('api')->user());
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    public function submitForgetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = DB::table('users')->where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Email not found.'], 404);
        }

        $token = Str::random(60);
        $expiresAt = Carbon::now()->addMinutes(5);

        DB::transaction(function () use ($request, $token, $expiresAt) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now(),
                'expires_at' => $expiresAt
            ]);

            Mail::send('emails.forget-password', ['token' => $token, 'resetLink' => url('/reset-password/' . $token)], function ($message) use ($request) {
                $message->to($request->email)->subject('Reset Password');
            });
        });

        return response()->json(['status' => true, 'message' => 'We have emailed your password reset link!'], 200);
    }

    public function submitResetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required',
            'password' => 'required|string|min:6|confirmed'
        ]);

        $tokenData = DB::table('password_reset_tokens')
                       ->where('token', $request->token)
                       ->where('expires_at', '>', Carbon::now())
                       ->first();

        if (!$tokenData || $tokenData->email !== $request->email) {
            return response()->json(['error' => 'Invalid or expired token.'], 400);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $user->password = Hash::make($request->password);
        $user->save();
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['status' => true, 'message' => 'Your password has been changed!'], 200);
    }

    protected function respondWithToken(string $token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => auth('api')->user(),
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ]);
    }
}
