<?php
namespace App\Traits\Providers;

use App\Models\GamesKey;
use App\Models\Order;
use App\Models\User;
use App\Traits\Missions\MissionTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as FacadesRequest;
use App\Helpers\Core as Helper;

trait PlayFiverTrait
{
    use MissionTrait;

    /**
     * @var string
     */
    protected static $urlPlayFiver;
    protected static $secretPlayFiver;
    protected static $codePlayFiver;
    protected static $tokenPlayFiver;
    protected static $rtpPlayFiver;

    private static function credencialFiverPlay()
    {
        $setting = GamesKey::first();
        self::$urlPlayFiver = $setting->getAttributes()['playfiver_url'];
        self::$rtpPlayFiver = $setting->getAttributes()['playfiver_rtp']; 
        self::$secretPlayFiver = $setting->getAttributes()['playfiver_secret'];
        self::$codePlayFiver = $setting->getAttributes()['playfiver_code'];
        self::$tokenPlayFiver = $setting->getAttributes()['playfiver_token'];
    }

    public static function playFiverLaunch($id, $demo)
    {
        self::credencialFiverPlay();

        $user_rtp = self::$rtpPlayFiver;

        
        if (!is_numeric($user_rtp) || (int)$user_rtp < 1 || (int)$user_rtp > 95) {
            $user_rtp = 50;
        } else {
            $user_rtp = (int)$user_rtp;
        }


        $postArray = [
            "agentToken" => self::$tokenPlayFiver,
            "secretKey" => self::$secretPlayFiver,
            "user_code" => Auth::guard("api")->user()->email,
            "game_code" => $id,
            "user_balance" => self::getBalancePlayFiver(Auth::guard("api")->user()->id),
            "user_rtp" => $user_rtp

        ];
        $response = Http::withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ])->post(self::$urlPlayFiver . "/api/v2/game_launch", $postArray);

        Log::info($response);
        if ($response->successful()) {
            $data = $response->json();
            return ["launch_url" => $data['launch_url']];
        } else {
            $data = $response->body();
            return $data; // Adicionado return para casos de falha
        }
    }
    public static function webhookPlayFiverAPI(Request $request)
    {
        $tipo = $request->input("type");
        switch ($tipo) {
            case 'Balance':
                return self::getBalancePlayFiverAPI($request);
            case 'WinBet':
                return self::percaOuGanhoPlayFiver($request);
            case 'Refund':
                return self::refundPlayFiverApi($request);
        }
    }
    private static function getBalancePlayFiverAPI($dados)
    {
        $user = User::where("email", $dados->input("user_code"))->first();
        if ($user != null) {
            $saldo = (float)$user->wallet->balance + (float)$user->wallet->balance_bonus + (float) +(float)$user->wallet->balance_withdrawal;
            return response()->json(["msg" => "", "balance" => number_format($saldo, 2, ".", ".")]);
        } else {
            return response()->json(["msg" => "INVALID_USER", "balance" => 0]);
        }
    }
    private static function refundPlayFiverApi($dados)
    {
        $user = User::where("email", $dados->input("user_code"))->first();
        $detalhes = $dados->input($dados->input("game_type"));
        $order = Order::where("round_id", $detalhes["round_id"])->first();
        if ($order == null || $user == null) {
            return response()->json(["msg" => "INVALID_USER", "balance" => 0]);
        }
        $order->update(["refunded" => true]);
        $wallet = $user->wallet;
        $user->wallet->increment("balance_withdrawal", $detalhes['win']);
        $saldo = ((float)$wallet->balance + (float)$wallet->balance_bonus + (float)$wallet->balance_withdrawal) + $detalhes['win'];

        return response()->json(["msg" => "", "balance" => $saldo]);
    }
    private static function percaOuGanhoPlayFiver($dados)
    {
        self::credencialFiverPlay();
        $user = User::where("email", $dados->input("user_code"))->first();
        if ($user != null) {
            $wallet = $user->wallet;
            $detalhes = $dados->input($dados->input("game_type"));

            $bet = $detalhes['bet'];
            $win = $detalhes['win'];
            $saldoAnt = (float)$wallet->balance + (float)$wallet->balance_bonus + (float)$wallet->balance_withdrawal;
            $saldo = ((float)$wallet->balance + (float)$wallet->balance_bonus + (float)$wallet->balance_withdrawal) - $bet + $win;
            $id = rand(0, 9999999999);
            $changeBonus = null;
            if ($saldoAnt >= $bet) {
                if ($wallet->balance_bonus > $bet) {
                    $wallet->decrement('balance_bonus', $bet);
                    $changeBonus = 'balance_bonus';
                } elseif ($wallet->balance >= $bet) {
                    $wallet->decrement('balance', $bet);
                    $changeBonus = 'balance';
                } elseif ($wallet->balance_withdrawal >= $bet) {
                    $wallet->decrement('balance_withdrawal', $bet);
                    $changeBonus = 'balance_withdrawal';
                } else {
                    $changeBonus = "balance";
                    if ($saldoAnt >= $bet) {
                        $valorPerdido =  $bet;
                        $balanceBonus = $wallet->balance_bonus;
                        $balance = $wallet->balance;
                        $balanceWithdrawal = $wallet->balance_withdrawal;

                        if ($balanceBonus >= $valorPerdido) {
                            $balanceBonus -= $valorPerdido;
                            $valorPerdido = 0;
                        } else {
                            $valorPerdido -= $balanceBonus;
                            $balanceBonus = 0;
                        }


                        if ($balance >= $valorPerdido) {
                            $balance -= $valorPerdido;
                            $valorPerdido = 0;
                        } else {
                            $valorPerdido -= $balance;
                            $balance = 0;
                        }


                        if ($balanceWithdrawal >= $valorPerdido) {
                            $balanceWithdrawal -= $valorPerdido;
                            $valorPerdido = 0;
                        } else {
                            $valorPerdido -= $balanceWithdrawal;
                            $balanceWithdrawal = 0;
                        }


                        $wallet->update([
                            "balance_bonus" => $balanceBonus,
                            "balance" => $balance,
                            "balance_withdrawal" => $balanceWithdrawal
                        ]);
                    }
                }
                if ($bet == 0 && $win != 0) {
                    $changeBonus = "balance";
                }

                if ($bet != 0 || $win != 0) {
                    Order::create([
                        "user_id" => $user->id,
                        "session_id" => $detalhes['round_id'],
                        "transaction_id" => $detalhes['txn_id'],
                        "game" => $detalhes['game_code'],
                        "game_uuid" => $detalhes['game_code'],
                        "type" => $win == 0 ? "bet" : "win",
                        "type_money" => $changeBonus,
                        "amount" =>  $win == 0 ? $bet : $win,
                        "providers" => "play_fiver",
                        "refunded" => false,
                        "round_id" => $detalhes['round_id'],
                        "status" => true
                    ]);
                    Helper::generateGameHistory($user->id, $win == 0 ? "bet" : "win", $win, $bet, $changeBonus, $detalhes['txn_id']);
                }

                return response()->json(["msg" => "", "balance" => $saldo]);
            } else {
                return response()->json(["msg" => "INSUFFICIENT_USER_FUNDS"]);
            }
        } else {
            return response()->json(["msg" => "INVALID_USER"]);
        }
    }
    private static function getBalancePlayFiver($id)
    {
        $user = User::where("id", $id)->first();
        if ($user != null) {
            $saldo = (float)$user->wallet->balance + (float)$user->wallet->balance_bonus + (float) +(float)$user->wallet->balance_withdrawal;
            return $saldo;
        } else {
            return 0;
        }
    }
}
 