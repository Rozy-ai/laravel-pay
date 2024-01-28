<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\BaseController as BaseController;
use App\Http\Requests\Pays\PayActionRequest;
use App\Http\Requests\Pays\PayFormRequest;
use App\Http\Requests\Pays\PayRequest;
use App\Http\Requests\Pays\TrustPaymentRequest;
use App\Http\Resources\Account\AccountResource;
use App\Services\PayService;
use Illuminate\Http\JsonResponse;
use Validator;

class PayController extends BaseController
{
    public function __construct(private PayService $pay_service)
    {
    }

    public function index(PayRequest $request): JsonResponse
    {
        $data = $request->validated();
        $pays = $this->pay_service->getPays($data);
        $partnership = auth()->user()->partnership;
        $account = auth()->user()->partnership->account;
        $to_pay = $this->pay_service->getToPay();
        $result = [];

        if($account) {
            $result = [
                'paid_to' => max($partnership->paid_to, $partnership->payment_delay),
                'payment_delay' => $partnership->payment_delay,
                'is_blocked' => $partnership->is_blocked,
                'account' => AccountResource::make($account),
                'name' => $account->name,
                'contract' => $partnership->contract,
                'account_number' => str_pad($account->id, 9, "0", STR_PAD_LEFT),
                'account_balance' => $account->account_balance,
                'to_pay' => $to_pay,
                'pays' => $pays,
            ];
        }

        return $this->sendResponse($result, 'Pays');
    }

    public function enablePro(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->enablePro($data);

        return $this->sendResponse($result, 'Pro');
    }

    public function disablePro(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->disablePro($data);

        return $this->sendResponse($result, 'Pro');
    }

    public function enableBuh(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->enableBuh($data);

        return $this->sendResponse($result, 'Buh');
    }

    public function disableBuh(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->disableBuh($data);

        return $this->sendResponse($result, 'Buh');
    }

    public function enableSeo(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->enableSeo($data);

        return $this->sendResponse($result, 'Seo');
    }

    public function disableSeo(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->disableSeo($data);

        return $this->sendResponse($result, 'Seo');
    }

    public function enableSeoExpress(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->enableSeo($data, 'seo_express');

        return $this->sendResponse($result, 'Seo');
    }

    public function disableSeoExpress(PayActionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->pay_service->disableSeo($data, 'seo_express');

        return $this->sendResponse($result, 'Seo');
    }

    public function form(PayFormRequest $request): JsonResponse
    {
        $data = $request->validated();
        $form = $this->pay_service->getForm($data);

        return $this->sendResponse($form, 'Pay form');
    }
}
