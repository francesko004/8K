<?php

namespace App\Traits\Gateways;

use App\Models\AffiliateHistory;
use App\Models\Deposit;
use App\Models\GamesKey;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\SuitPayPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NewDepositNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Helpers\Core as Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait SuitpayTrait
{
    /**
     * @var $uri
     * @var $clienteId
     * @var $clienteSecret
     */
    protected static string $uri;
    protected static string $clienteId;
    protected static string $clienteSecret;

  
    private static function generateCredentials()
    {
        $setting = Gateway::first();
        if(!empty($setting)) {
            self::$uri = $setting->getAttributes()['suitpay_uri'];
            self::$clienteId = $setting->getAttributes()['suitpay_cliente_id'];
            self::$clienteSecret = $setting->getAttributes()['suitpay_cliente_secret'];
        }
    }

    /**
     * Request QRCODE
     * Metodo para solicitar uma QRCODE PIX
     * @dev @dracman999
     * @return array
     */
public static function requestQrcode($request)
{
    try {
        // Log: Início da solicitação de QR Code
        \Log::info('[vizzerpay] Iniciando solicitação de QR Code...', ['request' => $request->all()]);

        // Obtendo configurações
        $setting = \Helper::getSetting();

        // Validando os dados recebidos
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:' . $setting->min_deposit, 'max:' . $setting->max_deposit],
            'cpf'    => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            \Log::warning('[vizzerpay] Validação falhou', ['errors' => $validator->errors()]);
            return response()->json($validator->errors(), 400);
        }

        // Gerar as credenciais
        self::generateCredentials();

        // Gerar o ID único para a transação
        $idUnico = uniqid();
        \Log::info('[vizzerpay] ID único gerado', ['idUnico' => $idUnico]);

        // Dados a serem enviados para gerar o QR Code
        $postData = [
            'client_id' => self::$clienteId,
            'client_secret' => self::$clienteSecret,
            'nome' => auth('api')->user()->name,
            'documento' => \Helper::soNumero($request->input("cpf")),
            'valor' => (float) $request->input("amount"),
            'descricao' => 'Depósito via PIX',
            'urlnoty' => url('/callback'),
            'telefone' => \Helper::soNumero(auth('api')->user()->phone),
            'email' => auth('api')->user()->email,
        ];

        // URL de requisição para a API
        $url = self::$uri . 'pix/qrcode';
        \Log::info('[vizzerpay] Enviando requisição para gerar QR Code', [
            'url' => $url,
            'postData' => json_encode($postData)
        ]);

        // Enviar requisição para a API
        $response = Http::asForm()->post($url, $postData);

        // Log detalhado da resposta da API
        \Log::info('[vizzerpay] Resposta da API recebida', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        // Verificar se a resposta foi bem-sucedida
        if ($response->successful()) {
            $responseData = $response->json();
            \Log::info('[vizzerpay] Resposta da API processada', ['responseData' => $responseData]);

            // Verificar se o reference_code (antigo external_id) foi retornado
            if (!isset($responseData['reference_code'])) {
                \Log::error('[vizzerpay] Chave "reference_code" não encontrada na resposta');
                return response()->json(['error' => 'Resposta inválida da API'], 500);
            }

            // Obter o reference_code (que é o external_id)
            $externalId = $responseData['reference_code'] ?? null;
            \Log::info('[vizzerpay] External ID obtido', ['external_id' => $externalId]);

            // Realizar a transação e o depósito dentro de uma transação DB
            DB::transaction(function () use ($responseData, $request, $idUnico, $externalId) {
                \Log::info('[vizzerpay] Iniciando transação e depósito', [
                    'transactionId' => $responseData['reference_code'],
                    'amount' => $request->input("amount"),
                    'external_id' => $externalId
                ]);

                // Salvar a transação com o external_id
                self::generateTransaction($responseData['reference_code'], $request->input("amount"), $externalId);

                // Salvar o depósito com o external_id
                self::generateDeposit($responseData['reference_code'], $request->input("amount"), $externalId);
            });

            // Adicionar uma chave de sessão para o frontend disparar a função JS
            session(['qr_response' => [
                'status' => 'QRCode gerado com sucesso',
                'transaction_id' => $responseData['reference_code']
            ]]);

            // Enviar resposta com o QR Code e o externalId
            \Log::info('[vizzerpay] Requisição processada com sucesso', [
                'transactionId' => $responseData['reference_code'],
                'qrcode' => $responseData['qrcode'] ?? 'QRCode não encontrado'
            ]);

            return response()->json([
                'status' => true,
                'transactionId' => $responseData['reference_code'], 
                'qrcode' => $responseData['qrcode'] ?? null,
                'externalId' => $externalId 
            ]);
        }

        // Log: Falha na geração do QR Code
        \Log::error('[vizzerpay] Falha na geração do QR Code', [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'response' => $response->body()
        ]);
        return response()->json(['error' => "Ocorreu uma falha ao entrar em contato com o banco."], 500);

    } catch (\Exception $e) {
        // Log: Erro inesperado
        \Log::error('[vizzerpay] Erro ao solicitar QR Code', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Erro interno'], 500);
    }
}

    /**
     * Consult Status Transaction
     * Consultar o status da transação
     * @dev @dracman999
     *
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */

public static function consultStatusTransaction()
{
    Log::info('Iniciando consulta de status das últimas 5 transações');

    self::generateCredentials();

    try {
        // 1. Buscar as últimas 5 transações que ainda não foram pagas (status != 1)
        Log::debug('Buscando últimas 5 transações pendentes no banco de dados');

        $transactions = Transaction::where('status', '!=', 1)
            ->latest()
            ->take(5)
            ->get();

        if ($transactions->isEmpty()) {
            Log::info('Nenhuma transação pendente encontrada.');
            return response()->json(['message' => 'Nenhuma transação pendente'], 200);
        }

        // 2. Filtrar transações com menos de 10 minutos de diferença
        $validTransactions = [];
        foreach ($transactions as $transaction) {
            $timeDifference = now()->diffInMinutes($transaction->updated_at);

            if ($timeDifference <= 10) {
                $validTransactions[] = $transaction->external_id;
            } else {
                Log::info('Transação ignorada por estar acima do limite de tempo', [
                    'external_id' => $transaction->external_id,
                    'time_difference' => $timeDifference
                ]);
            }
        }

        if (empty($validTransactions)) {
            Log::info('Nenhuma transação válida para consulta.');
            return response()->json(['message' => 'Nenhuma transação recente para consultar'], 200);
        }

        // 3. Consultar status das transações válidas
        $responses = [];
        foreach ($validTransactions as $externalId) {
            $statusUrl = 'https://duspay.com.br/libs/consult/transaction_status?id=' . $externalId;

            Log::info('Consultando status da transação', ['external_id' => $externalId, 'url' => $statusUrl]);

            $response = Http::withHeaders([
                'ci' => self::$clienteId,
                'cs' => self::$clienteSecret
            ])->get($statusUrl);

            if (!$response->successful()) {
                Log::error('Falha na comunicação com VizzerPay', [
                    'external_id' => $externalId,
                    'status' => $response->status()
                ]);
                $responses[$externalId] = ['status' => 'pendente'];
                continue;
            }

            $statusData = $response->json();

            if (isset($statusData['data']['status'])) {
                $transactionStatus = $statusData['data']['status'];
                Log::info('Status recebido', ['external_id' => $externalId, 'status' => $transactionStatus]);

                if ($transactionStatus === 'PAID') {
                    Log::notice('Pagamento confirmado', ['external_id' => $externalId]);

                    // Chama a função para processar a finalização do pagamento
                    if (self::finalizePayment($externalId)) {
                        Log::info('Pagamento finalizado com sucesso', ['external_id' => $externalId]);
                    } else {
                        Log::alert('Falha na finalização do pagamento', ['external_id' => $externalId]);
                    }
                }

                $responses[$externalId] = ['status' => $transactionStatus];
            } else {
                Log::error('Resposta mal formatada para transação', ['external_id' => $externalId]);
                $responses[$externalId] = ['error' => 'Resposta inválida'];
            }
        }

        return response()->json($responses);
    } catch (\Exception $e) {
        Log::critical('Erro crítico no processo', [
            'erro' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json(['error' => 'Erro interno'], 500);
    }
}


    /*
     * @param $idTransaction
     * @dev @dracman999
     * @return bool
     */
public static function finalizePayment($externalId) : bool
{
    // Log inicial para verificar se o externalId foi recebido corretamente
    \Log::info("Iniciando finalização do pagamento com external_id: $externalId");

    // Altera a busca para usar o external_id
    $transaction = Transaction::where('external_id', $externalId)->where('status', 0)->first();
    if (!$transaction) {
        \Log::error("Transação não encontrada para o external_id: $externalId");
        return false;
    }
    \Log::info("Transação encontrada para o external_id: $externalId, id da transação: " . $transaction->id);

    $setting = \Helper::getSetting();
    \Log::info("Configurações carregadas para o pagamento.");

    if (!empty($transaction)) {
        $user = User::find($transaction->user_id);
        \Log::info("Usuário encontrado, id: " . $user->id);

        $wallet = Wallet::where('user_id', $transaction->user_id)->first();
        if (!empty($wallet)) {
            \Log::info("Carteira encontrada para o usuário.");

            $setting = Setting::first();

            // Verifica se é o primeiro depósito
            $checkTransactions = Transaction::where('user_id', $transaction->user_id)
                ->where('status', 1)
                ->count();
            \Log::info("Verificando transações anteriores do usuário. Transações pagas encontradas: $checkTransactions");

            if ($checkTransactions == 0 || empty($checkTransactions)) {
                // Paga o bônus inicial
                $bonus = Helper::porcentagem_xn($setting->initial_bonus, $transaction->price);
                \Log::info("Pagando bônus inicial: $bonus");
                $wallet->increment('balance_bonus', $bonus);
                $wallet->update(['balance_bonus_rollover' => $bonus * $setting->rollover]);
            }

            // Rollover do depósito
            $wallet->update(['balance_deposit_rollover' => $transaction->price * intval($setting->rollover_deposit)]);
            \Log::info("Aplicando rollover ao depósito.");

            // Acumula bônus VIP
            Helper::payBonusVip($wallet, $transaction->price);
            \Log::info("Pagando bônus VIP.");

            if ($wallet->increment('balance', $transaction->price)) {
                \Log::info("Saldo do usuário atualizado.");

                if ($transaction->update(['status' => 1])) {
                    \Log::info("Status da transação atualizado para 'pago'.");

                    // Procura o depósito correspondente
                    $deposit = Deposit::where('external_id', $externalId)->where('status', 0)->first();
                    if (!empty($deposit)) {
                        \Log::info("Depósito encontrado, id: " . $deposit->id);

                        // Processa o CPA
                        $affHistoryCPA = AffiliateHistory::where('user_id', $user->id)
                            ->where('commission_type', 'cpa')
                            ->where('status', 0)
                            ->first();
                        if (!empty($affHistoryCPA)) {
                            \Log::info("Verificando histórico de CPA.");

                            // Verifica se o CPA já pode ser pago
                            $sponsorCpa = User::find($user->inviter);
                            if (!empty($sponsorCpa)) {
                                \Log::info("Sponsor encontrado para CPA, id: " . $sponsorCpa->id);
                                if ($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline || $deposit->amount >= $sponsorCpa->affiliate_baseline) {
                                    $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->first();
                                    if (!empty($walletCpa)) {
                                        // Paga o CPA
                                        $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa);
                                        $affHistoryCPA->update(['status' => 1, 'commission_paid' => $sponsorCpa->affiliate_cpa]);
                                        \Log::info("CPA pago ao sponsor: " . $sponsorCpa->id);
                                    }
                                } else {
                                    $affHistoryCPA->update(['deposited_amount' => $transaction->price]);
                                    \Log::info("Devido a regras de CPA, atualização do valor depositado: " . $transaction->price);
                                }
                            }
                        }

                        if ($deposit->update(['status' => 1])) {
                            \Log::info("Depósito marcado como pago.");

                            $admins = User::where('role_id', 0)->get();
                            foreach ($admins as $admin) {
                                $admin->notify(new NewDepositNotification($user->name, $transaction->price));
                                \Log::info("Notificação de novo depósito enviada ao admin, id: " . $admin->id);
                            }
                        }
                    }
                }
            } else {
                \Log::error("Erro ao atualizar o saldo do usuário.");
            }
        } else {
            \Log::error("Carteira não encontrada para o usuário.");
        }
    } else {
        \Log::error("Transação não encontrada ou com status inválido.");
    }

    return true;
}

/**
 * @param $idTransaction
 * @param $amount
 * @param $externalId
 * @dev @dracman999
 * @return void
 */
private static function generateDeposit($idTransaction, $amount, $externalId)
{
    $userId = auth('api')->user()->id;
    $wallet = Wallet::where('user_id', $userId)->first();

    Deposit::create([
        'payment_id'=> $idTransaction,
        'user_id'   => $userId,
        'amount'    => $amount,
        'type'      => 'pix',
        'currency'  => $wallet->currency,
        'symbol'    => $wallet->symbol,
        'status'    => 0,
        'external_id' => $externalId, // Adicionando o external_id
    ]);
}

/**
 * @param $idTransaction
 * @param $amount
 * @param $externalId
 * @dev @dracman999
 * @return void
 */
private static function generateTransaction($idTransaction, $amount, $externalId)
{
    $setting = \Helper::getSetting();

    Transaction::create([
        'payment_id' => $idTransaction,
        'user_id' => auth('api')->user()->id,
        'payment_method' => 'pix',
        'price' => $amount,
        'currency' => $setting->currency_code,
        'status' => 0,
        'external_id' => $externalId, // Adicionando o external_id
    ]);
}

    /**
     * @param $request
     * @dev @dracman999
     * @return \Illuminate\Http\JsonResponse|void
     */
    public static function pixCashOut(array $array): bool
    {
        self::generateCredentials();

        $response = Http::withHeaders([
            'ci' => self::$clienteId,
            'cs' => self::$clienteSecret
        ])->post(self::$uri.'pix/payment', [
            "key" => $array['pix_key'],
            "typeKey" => $array['pix_type'],
            "value" => $array['amount'],
            'callbackUrl' => url('/calback'),
        ]);

        if($response->successful()) {
            $responseData = $response->json();

            if($responseData['response'] == 'OK') {
                $suitPayPayment = SuitPayPayment::lockForUpdate()->find($array['suitpayment_id']);
                if(!empty($suitPayPayment)) {
                    if($suitPayPayment->update(['status' => 1, 'payment_id' => $responseData['idTransaction']])) {
                        return true;
                    }
                    return false;
                }
                return false;
            }
            return false;
        }
        return false;
    }
}