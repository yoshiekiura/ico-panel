<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Client;
use App\Token;
use App\BankReceipt;
use App\TransactionLog;

class BankReceiptController extends Controller
{

    public function __construct() {
        $this->middleware('auth');
    }
    /**
     * Pay with bank transfer
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request) {
        $this->validate($request, [
            'token' => 'required|exists:tokens,id',
            'token_amount' => 'required|numeric',
            'eth_value' => 'required|numeric',
            'usd_value' => 'required|numeric'
        ]);
        $data['token'] = Token::find($request->input('token'));
        $data['eth_value'] = $request->input('eth_value');
        $data['usd_value'] = $request->input('usd_value');
        $data['token_amount'] = $request->input('token_amount');
        $data['order_id'] = date('Ym') . substr(hexdec(uniqid()), 0, 8);

        return view('receipt.create', $data);
    }

    /**
     * Save Bank transfer Reciept
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        $this->validate($request, [
            'token' => 'required|exists:tokens,id',
            'token_amount' => 'required|numeric',
            'usd_value' => 'required|numeric',
            'eth_value' => 'required|numeric',
            'order_id' => 'required|string',
            'bank_name' => 'required|string',
            'account_name' => 'required|string',
            'account_number' => 'required|string',
            'receipt' => 'required|file'
        ]);

        if($request->file('receipt')->isValid()) {
            $receipt = $request->file('receipt')->store('receipt', 'public');
        }

        BankReceipt::create([
            'user_id' => Auth::user()->id,
            'token_id' => $request->input('token'),
            'order_id' => $request->input('order_id'),
            'bank_name' => $request->input('bank_name'),
            'account_name' => $request->input('account_name'),
            'account_number' => $request->input('account_number'),
            'usd_value' => 100 * $request->input('usd_value'),
            'eth_value' => $request->input('eth_value'),
            'token_value' => $request->input('token_amount'),
            'receipt' => $receipt
        ]);
        
        $data['heading'] = 'Thank you';
        $data['message'] = 'Successfully submitted receipt. We will review soon and deliver coins';
        $data['description'] = 'Please email me if you have any questions';
        $data['link_label'] = 'Go to Dashboard';
        $data['link'] = route('users.dashboard');

        return view('layouts.error', $data);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data['receipts'] = BankReceipt::orderBy('status')->orderBy('created_at', 'desc')->get();
        return view('receipt.index', $data);
    }

    public function approve(BankReceipt $receipt)
    {
        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => env('TOKEN_API_URL'),
            // You can set any number of default request options.
            'timeout'  => 20.0
        ]);
        $tokenRequestParams = [
            'artist_address' => $receipt->token->user->wallet[0]->address,
            'beneficiary_address' => $receipt->user->wallet[0]->address,
            'amount' => $receipt->token_value
        ];
        $response = $client->request('POST', 'ico/allocate', [
            'http_errors' => false,
            'json' => $tokenRequestParams,
            'headers' => [
                'Authorization' => 'API-KEY ' . env('TOKEN_API_KEY')
            ]
        ]);

        if ($response->getStatusCode() == 200) {
            $result = json_decode($response->getBody()->getContents());
            if ($result->success) {
                $receipt->status = 1;
                $receipt->transactionLogs()->create([
                    'from' => $tokenRequestParams['beneficiary_address'],
                    'to' => '0x0',
                    'usd_value' => $receipt->usd_value,
                    'eth_value' => $receipt->eth_value,
                    'token_value' => $receipt->token_value,
                    'token_id' => $receipt->token->id,
                    'tx_hash' => $result->tx_hash,
                    'transaction_type_id' => 2
                ]);
                $receipt->save();
                return response()->json([
                    'success' => true
                ]);
            }
        }

        return response()->json([
            'message' => false
        ], 500);
    }

    public function dismiss(BankReceipt $receipt)
    {
        $receipt->status = 3;
        $receipt->save();
        return response()->json([
            'success' => true
        ]);
    }
}
