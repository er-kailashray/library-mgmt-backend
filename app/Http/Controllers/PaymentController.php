<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request)
    {
        return response()->json([
            'status' => true,
            'data' => $this->payments->listForLibrary($this->library($request)),
        ]);
    }

    public function store(StorePaymentRequest $request)
    {
        $payment = $this->payments->record($this->library($request), $request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Payment recorded successfully',
            'data' => $this->payments->present($payment),
        ], 201);
    }
}
