<?php

namespace App\Services;

use App\Helpers\PayFormHelper;
use App\Http\Resources\Account\BalanceHistoryResource;
use App\Models\CompanyBalanceHistory;
use App\Models\ModulbankBill;
use App\Models\Partnership;
use App\Models\Partnership\PartnershipBill;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class PayService
{
    const TRUST_PRICE = [
        3 => 335,
        7 => 428,
        10 => 475,
    ];

    public function getToPay()
    {
        $account = auth()->user()->partnership->account;
        $pay_names = [
            'royalty' => 'Подписка',
            'sms' => 'Платеж за аккаунт',
            'call' => 'Контактный центр',
            'buh' => 'Бухгалтерские услуги',
            'seo' => 'Продвижение сайта',
            'seo_express' => 'Продвижение сайта Экспресс',
            'trust_payment' => 'Доверительный платеж',
        ];
        $ids = $account->partnerships()->where('is_archive', 0)->pluck('id');
        $pays = QueryBuilder::for(PartnershipBill::class)
            ->selectRaw('SUM(IF(is_pro, sum + pro_sum, sum)) as sum, type, partnership_id')
            ->whereIn('partnership_id', $ids)
            ->where('date', '<=', date('Y-m-01'))
            ->where('paid', '=', 0)
            ->where(function($query){
                return $query->where('is_required', '=', 1)
                    ->orWhere('is_enabled', '=', 1);
            })
            ->groupBy('type')
            ->get();

        $debt = QueryBuilder::for(PartnershipBill::class)
            ->whereIn('partnership_id', $ids)
            ->when(date('d') > 5, function ($q) {
                return $q->where('date', '<=', date('Y-m-01'));
            }, function ($q) {
                return $q->where('date', '<', date('Y-m-01'));
            })
            ->where('date', '<=', date('Y-m-01'))
            ->where('paid', '=', 0)
            ->where(function($query){
                return $query->where('is_required', '=', 1)
                    ->orWhere('is_enabled', '=', 1);
            })
            ->sum('sum');

        $debt = $account->account_balance >= $debt ? 0 : $debt - $account->account_balance;

        $items = $pays->map(function ($pay) use ($pay_names) {
            return [
                'name' => $pay_names[$pay->type],
                'type' => $pay->type,
                'sum' => $pay->sum
            ];
        });

        $to_pay = [
            'items' => $items,
            'debt' => $debt,
            'total_sum' => $pays->sum('sum')
        ];

        return $to_pay;
    }

    public function getPays($data)
    {
        $pay_names = [
            'trust_payment' => 'Доверительный платеж',
            'sms' => 'Платеж за аккаунт',
            'call' => 'Контактный центр',
            'buh' => 'Бухгалтерские услуги',
            'seo' => 'Продвижение сайта',
            'seo_express' => 'Продвижение сайта Экспресс',
        ];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
        $partnership_id = auth()->user()->partnership_id;
        $partnership = auth()->user()->partnership;

        $pays = [];
        $total_sum = 0;
        $to_pay = 0;
        $required_sum = 0;
        // Подписка royalties
        $royalties = $this->getBills($date, 'royalty');
        foreach ($royalties as $royalty)
        {
            $changeable = 1;
            if(($royalty->paid && $royalty->is_pro) || ($royalty->is_pro && $date->format('Y-m-01') <= date('Y-m-01')) || $partnership->is_pro_free)
            {
                $changeable = 0;
            }
            $sum = $royalty->is_pro ? $royalty->sum + $royalty->pro_sum : $royalty->sum;
            $pro_sum = $this->calcProSum($royalty);

            $pays['items'][] = [
                'name' => $royalty->is_pro ? 'Профессиональная подписка' : 'Начальная подписка',
                'partnership' => [
                    'id' => $royalty->partnership->id,
                    'name' => $royalty->partnership->name,
                    'city' => $royalty->partnership->city
                ],
                'type' => 'royalty',
                'sum' => $sum,
                'pro_sum' => $pro_sum,
                'is_pro' => $royalty->is_pro,
                'is_required' => $royalty->is_required,
                'paid' => $royalty->paid,
                'changeable' => $changeable,
                'is_enabled' => 1,
                'num' => 1,
                'days' => $royalty->days,
            ];
            $total_sum += $sum;
            if(!$royalty->paid)
            {
                $to_pay += $sum;
                $required_sum += $sum;
            }
        }
        foreach($pay_names as $key => $name)
        {
            $bill_date = $key == 'call' ? $date->copy()->subMonthsNoOverflow() : $date;
            $pay_items = $this->getBills($bill_date, $key);

            foreach ($pay_items as $pay_item) {
                $changeable = 1;
                if(
                    $pay_item->is_required ||
                    $pay_item->paid ||
                    ($pay_item->is_enabled && date('Y-m-d') > $date->format('Y-m-05')) ||
                    $date->format('Y-m-01') < date('Y-m-01')
                )
                {
                    $changeable = 0;
                }
                $num = 1;
                $sum = $pay_item->sum;

                $pays['items'][] = [
                    'name' => $name,
                    'partnership' => [
                        'id' => $pay_item->partnership->id,
                        'name' => $pay_item->partnership->name,
                        'city' => $pay_item->partnership->city . ($pay_item->additional_partnership_id ? ' (доп)' : '')
                    ],
                    'type' => $pay_item->type,
                    'sum' => $sum,
                    'is_required' => $pay_item->is_required,
                    'paid' => $pay_item->paid,
                    'changeable' => $changeable,
                    'is_enabled' => $pay_item->is_enabled,
                    'num' => $num,
                    'days' => $pay_item->days,
                ];
                if($pay_item->is_enabled)
                    $total_sum += $sum;

                if($pay_item->is_required && !$pay_item->paid)
                    $required_sum += $sum;

                if(!$pay_item->paid && $pay_item->is_enabled)
                    $to_pay += $sum;
            }
        }

        $history = $partnership->balanceHistory()
            ->where('date', '>=', $date->format('Y-m-01'))
            ->where('date', '<=', $date->format('Y-m-t 23:59:59'))
            ->orderByDesc('date')
            ->get();

        $pays['total_sum'] = $total_sum;
        $pays['to_pay_sum'] = $to_pay;
        $pays['required_sum'] = $required_sum;
        $pays['history'] = BalanceHistoryResource::collection($history);

        return $pays;
    }

    public function enablePro($data)
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
        $partnership = auth()->user()->partnership;

        // Подписка
        $royalty = $this->getBill($date, 'royalty', $data['id']);
        $royalty_next = $this->getBill($date->copy()->addMonthsNoOverflow(), 'royalty', $data['id']);
        if(!$this->canEdit($royalty)) return $result;

        if(!$royalty->paid && $date->format('Y-m-01') >= date('Y-m-01')) {
            $royalty->is_pro = 1;
            $royalty->save();
            if($royalty_next){
                $royalty_next->is_pro = 1;
                $royalty_next->save();
            }
            if($date->format('Y-m-01') == date('Y-m-01')) {
                auth()->user()->partnership->is_pro = 1;
                auth()->user()->partnership->save();
            }

            $result['status'] = 'changed';
        }elseif(
            $royalty->paid &&
            $date->format('Y-m-01') == date('Y-m-01')
        ){
            $pro_sum = $this->calcProSum($royalty);
            if($partnership->account->account_balance >= $pro_sum)
            {
                $partnership->account->account_balance -= $pro_sum;
                $partnership->account->save();

                $royalty->pro_sum = $pro_sum;
                $royalty->is_pro = 1;
                $royalty->save();
                if($royalty_next){
                    $royalty_next->is_pro = 1;
                    $royalty_next->save();
                }

                $partnership->is_pro = 1;
                $partnership->save();

                $partnership->graph()->where('date', '=', $date->format('Y-m-01'))
                    ->increment('royalty_pay_summ', $pro_sum);

                $balance = [
                    'company_id' => $partnership->account->id,
                    'partnership_id' => $data['id'],
                    'type' => 'outcome',
                    'service' => 'royalty_pro',
                    'sum' => $pro_sum
                ];
                CompanyBalanceHistory::create($balance);

                $result['status'] = 'changed';
            }else{
                $result['status'] = 'deposit';
                $result['sum'] = $partnership->account->account_balance - $pro_sum;
            }
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    public function disablePro($data)
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();

        // Подписка
        $royalty = $this->getBill($date, 'royalty', $data['id']);
        if(!$this->canEdit($royalty)) return $result;

        if(!$royalty->paid && $date->format('Y-m-01') > date('Y-m-01'))
        {
            $royalty->is_pro = 0;
            $royalty->save();
            $result['status'] = 'changed';
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    private function calcProSum($royalty)
    {
        $pro_sum = $royalty->pro_sum;
        $date = Carbon::parse($royalty->date);
        if($royalty->paid && !$royalty->is_pro && $date->format('Y-m-01') == date('Y-m-01'))
        {
            $now = Carbon::now();
            $remaining_days = $now->daysInMonth - $now->day;
            $pro_sum = ceil($royalty->pro_sum/$now->daysInMonth * $remaining_days);
        }

        return $pro_sum;
    }

    private function getBills(Carbon $date, string|array $type, $partnership_id = 0)
    {
        $ids = auth()->user()->partnership->account->partnerships()->where('is_archive', 0)->pluck('id');

        $bills = QueryBuilder::for(PartnershipBill::class)
            ->with('partnership')
            ->when($partnership_id, function ($q) use ($partnership_id) {
                return $q->where('partnership_id', '=', $partnership_id);
            }, function ($q) use ($ids) {
                return $q->whereIn('partnership_id', $ids);
            })
            ->whereIn('partnership_id', $ids)
            //->where('additional_partnership_id', '=', 0)
            ->where('date', '=', $date->format('Y-m-01'))
            ->when(is_array($type), function ($q) use ($type) {
                return $q->whereIn('type', $type);
            }, function ($q) use ($type) {
                return $q->where('type', '=', $type);
            })
            ->get();

        return $bills;
    }

    private function getBill(Carbon $date, string|array $type, $partnership_id)
    {
        $bill = QueryBuilder::for(PartnershipBill::class)
            ->with('partnership')
            ->where('partnership_id', '=', $partnership_id)
            ->where('additional_partnership_id', '=', 0)
            ->where('date', '=', $date->format('Y-m-01'))
            ->when(is_array($type), function ($q) use ($type) {
                return $q->whereIn('type', $type);
            }, function ($q) use ($type) {
                return $q->where('type', '=', $type);
            })
            ->first();

        return $bill;
    }

    public function enableBuh($data)
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
        $partnership = auth()->user()->partnership;

        $buh = $this->getBill($date, 'buh', $data['id']);
        $buh_next = $this->getBill($date->copy()->addMonthsNoOverflow(), 'buh', $data['id']);
        if(!$this->canEdit($buh)) return $result;

        if(
            !$buh->paid &&
            (
                $date->format('Y-m-01') > date('Y-m-t') ||
                (
                    date('Y-m-d') <= $date->format('Y-m-05') &&
                    date('Y-m-01') == $date->format('Y-m-01')
                )
            )
        ){
            $buh->is_enabled = 1;
            $buh->save();
            $result['status'] = 'changed';
        }elseif(
            date('Y-m-d') > $date->format('Y-m-05') &&
            date('Y-m-01') == $date->format('Y-m-01')
        ){
            if($partnership->account->account_balance >= $buh->sum)
            {
                $partnership->account->account_balance -= $buh->sum;
                $partnership->account->save();

                $buh->is_enabled = 1;
                $buh->paid = 1;
                $buh->paid_date = date('Y-m-d H:i:s');
                $buh->save();

                if($buh_next){
                    $buh_next->is_enabled = 1;
                    $buh_next->save();
                }

                $partnership->graph()->updateOrCreate(
                    ['date' => $date->format('Y-m-01')],
                    ['buh_pay_date' => date('Y-m-d'), 'buh_pay_type' => 1, 'buh_pay_summ' => $buh->sum]
                );

                $balance = [
                    'company_id' => $partnership->account->id,
                    'partnership_id' => $data['id'],
                    'type' => 'outcome',
                    'service' => 'buh',
                    'sum' => $buh->sum
                ];
                CompanyBalanceHistory::create($balance);

                $result['status'] = 'changed';
            }else{
                $result['status'] = 'deposit';
                $result['sum'] = $partnership->account->account_balance - $buh->sum;
            }
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    public function disableBuh($data)
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();

        $buh = $this->getBill($date, 'buh', $data['id']);
        $buh_next = $this->getBill($date->copy()->addMonthsNoOverflow(), 'buh', $data['id']);
        if(!$this->canEdit($buh)) return $result;

        if(
            !$buh->paid &&
            (
                $date->format('Y-m-01') > date('Y-m-t') ||
                (
                    date('Y-m-d') <= $date->format('Y-m-05') &&
                    date('Y-m-01') == $date->format('Y-m-01')
                )
            )
        ){
            $buh->is_enabled = 0;
            $buh->save();

            if($buh_next){
                $buh_next->is_enabled = 0;
                $buh_next->save();
            }

            $result['status'] = 'changed';
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    public function enableSeo($data, $type = 'seo')
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
        $partnership = auth()->user()->partnership;

        $bills = $this->getBills($date, ['seo', 'seo_express'], $data['id']);
        if(!$this->canEdit($bills->first())) return $result;

        $paid = $bills->where('paid', '=', 1)->count();

        if($type == 'seo') {
            $first_seo = $bills->where('type', '=', 'seo')->first();
            $second_seo = $bills->where('type', '=', 'seo_express')->first();
        }else{
            $first_seo = $bills->where('type', '=', 'seo_express')->first();
            $second_seo = $bills->where('type', '=', 'seo')->first();
        }

        if(
            !$paid &&
            (
                $date->format('Y-m-01') > date('Y-m-t') ||
                (
                    date('Y-m-d') <= $date->format('Y-m-05') &&
                    date('Y-m-01') == $date->format('Y-m-01')
                )
            )
        ){
            $first_seo->is_enabled = 1;
            $first_seo->save();

            $second_seo->is_enabled = 0;
            $second_seo->save();

            $result['status'] = 'changed';
        }elseif(
            !$paid &&
            date('Y-m-d') > $date->format('Y-m-05') &&
            date('Y-m-01') == $date->format('Y-m-01')
        ){
            if($partnership->account->account_balance >= $first_seo->sum)
            {
                $partnership->account->account_balance -= $first_seo->sum;
                $partnership->account->save();

                $first_seo->is_enabled = 1;
                $first_seo->paid = 1;
                $first_seo->paid_date = date('Y-m-d H:i:s');
                $first_seo->save();

                $second_seo->is_enabled = 0;
                $second_seo->save();

                $this->enableNextSeo($date, $type, $data['id']);

                $partnership->graph()->updateOrCreate(
                    ['date' => $date->format('Y-m-01')],
                    ['seo_pay_date' => date('Y-m-d'), 'seo_pay_type' => 1, 'seo_pay_summ' => $first_seo->sum]
                );

                $balance = [
                    'company_id' => $partnership->account->id,
                    'partnership_id' => $data['id'],
                    'type' => 'outcome',
                    'service' => 'seo',
                    'sum' => $first_seo->sum
                ];
                CompanyBalanceHistory::create($balance);

                $result['status'] = 'changed';
            }else{
                $result['status'] = 'deposit';
                $result['sum'] = $partnership->account->account_balance - $first_seo->sum;
            }
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    private function enableNextSeo(Carbon $date, $type = 'seo', $partnership_id)
    {
        $bills = $this->getBills($date->copy()->addMonthsNoOverflow(), ['seo', 'seo_express'], $partnership_id);

        if($type == 'seo') {
            $first_seo = $bills->where('type', '=', 'seo')->first();
            $second_seo = $bills->where('type', '=', 'seo_express')->first();
        }else{
            $first_seo = $bills->where('type', '=', 'seo_express')->first();
            $second_seo = $bills->where('type', '=', 'seo')->first();
        }

        if($first_seo) {
            $first_seo->is_enabled = 1;
            $first_seo->save();
        }
        if($second_seo) {
            $second_seo->is_enabled = 0;
            $second_seo->save();
        }
    }

    public function disableSeo($data, $type = 'seo')
    {
        $result = [];
        $date = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();

        $seo = $this->getBill($date, $type, $data['id']);
        $seo_next = $this->getBill($date->copy()->addMonthsNoOverflow(), $type, $data['id']);
        if(!$this->canEdit($seo)) return $result;

        if(
            !$seo->paid &&
            (
                $date->format('Y-m-01') > date('Y-m-t') ||
                (
                    date('Y-m-d') <= $date->format('Y-m-05') &&
                    date('Y-m-01') == $date->format('Y-m-01')
                )
            )
        ){
            $seo->is_enabled = 0;
            $seo->save();

            if($seo_next){
                $seo_next->is_enabled = 0;
                $seo_next->save();
            }

            $result['status'] = 'changed';
        }else{
            $result['status'] = 'unavailable';
        }

        return $result;
    }

    private function canEdit($bill)
    {
        if(!$bill) return false;
        $ids = auth()->user()->partnership->account->partnerships()->pluck('id');
        return in_array($bill->partnership_id, $ids->toArray());
    }
}
