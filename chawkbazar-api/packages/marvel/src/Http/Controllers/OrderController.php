<?php

namespace Marvel\Http\Controllers;

use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\CannotInsertRecord;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderStatus;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\Permission;
use Marvel\Events\OrderCreated;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Http\Requests\OrderUpdateRequest;
use Omnipay\Common\Http\Exception;


class OrderController extends CoreController
{
    public $repository;

    public function __construct(OrderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Order[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit ?   $request->limit : 10;
        return $this->fetchOrders($request)->paginate($limit)->withQueryString();
    }

    public function fetchOrders(Request $request)
    {
        $user = $request->user();
        if ($user && ($user->hasPermissionTo(Permission::SUPER_ADMIN)||
                $user->hasPermissionTo(Permission::CEO)||
                $user->hasPermissionTo(Permission::MANAGEMENT)||
                $user->hasPermissionTo(Permission::LEGAL)||
                $user->hasPermissionTo(Permission::MANAGER_RH)||
                $user->hasPermissionTo(Permission::SHAREHOLDER)||
                $user->hasPermissionTo(Permission::MARKETING)) && (!isset($request->shop_id) || $request->shop_id === 'undefined')) {
            return $this->repository->with('children')->where('id', '!=', null)->where('parent_id', '=', null); //->paginate($limit);
        } else if ($this->repository->hasPermission($user, $request->shop_id)) {
            if ($user && $user->hasPermissionTo(Permission::STORE_OWNER)) {
                return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
            } elseif ($user && $user->hasPermissionTo(Permission::STAFF)) {
                return $this->repository->with('children')->where('shop_id', '=', $request->shop_id)->where('parent_id', '!=', null); //->paginate($limit);
            }
        } else {
            return $this->repository->with('children')->where('customer_id', '=', $user->id)->where('parent_id', '=', null); //->paginate($limit);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param OrderCreateRequest $request
     * @return LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function store(OrderCreateRequest $request)
    {
        return $this->repository->storeOrder($request);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        try {
            $order = $this->repository->with(['products', 'status', 'children.shop'])->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
        if (($user->hasPermissionTo(Permission::SUPER_ADMIN)||
            $user->hasPermissionTo(Permission::CEO)||
            $user->hasPermissionTo(Permission::MANAGEMENT)||
            $user->hasPermissionTo(Permission::LEGAL)||
            $user->hasPermissionTo(Permission::MANAGER_RH)||
            $user->hasPermissionTo(Permission::SHAREHOLDER)||
            $user->hasPermissionTo(Permission::MARKETING))) {
            return $order;
        } elseif (isset($order->shop_id)) {
            if ($this->repository->hasPermission($user, $order->shop_id)) {
                return $order;
            } elseif ($user->id == $order->customer_id) {
                return $order;
            }
        } elseif ($user->id == $order->customer_id) {
            return $order;
        } else {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
        }
    }
    public function findByTrackingNumber(Request $request, $tracking_number)
    {
        $user = $request->user();
        try {
            $order = $this->repository->with(['products', 'status', 'children.shop'])->findOneByFieldOrFail('tracking_number', $tracking_number);
            if ($user->id === $order->customer_id || $user->can('super_admin')) {
                return $order;
            } else {
                throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
            }
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param OrderUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(OrderUpdateRequest $request, $id)
    {
        $request->id = $id;
        $order = $this->updateOrder($request);
        return $order;
    }


    public function updateOrder(Request $request)
    {
        try {
            $order = $this->repository->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
        $user = $request->user();
        if (isset($order->shop_id)) {
            if ($this->repository->hasPermission($user, $order->shop_id)) {
                return $this->changeOrderStatus($order, $request->status,$request->id_proof_voucher_media);
            }
        } else if (($user->hasPermissionTo(Permission::SUPER_ADMIN)||
            $user->hasPermissionTo(Permission::CEO)||
            $user->hasPermissionTo(Permission::MANAGEMENT)||
            $user->hasPermissionTo(Permission::LEGAL)||
            $user->hasPermissionTo(Permission::MANAGER_RH)||
            $user->hasPermissionTo(Permission::SHAREHOLDER)||
            $user->hasPermissionTo(Permission::MARKETING))) {
            return $this->changeOrderStatus($order, $request->status,$request->id_proof_voucher_media);
        } else {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
        }
    }

    /**
     * @throws MarvelException
     */
    public function changeOrderStatus($order, $status, $voucher)
    {
        //id_proof_voucher_media
        $status_in_repository=OrderStatus::find($status);
        if($status_in_repository->requires_proof_voucher){
            if($voucher){
                $order->id_proof_voucher_media=$voucher;
            }else{
                throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
            }
        }
        $order->status = $status;
        $order->save();
        try {
            $children = json_decode($order->children);
        } catch (\Throwable $th) {
            $children = $order->children;
        }
        if (is_array($children) && count($children)) {
            foreach ($order->children as $child_order) {
                $child_order->status = $status;
                $child_order->save();
            }
        }
        return $order;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (\Exception $e) {
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }
    }

    /**
     * @throws MarvelException
     * @throws CannotInsertRecord
     */
    public function allOrdersInStore(Request $request, $id=null){
        if(!$request->user()->hasPermissionTo(Permission::STORE_OWNER))  throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_AUTHORIZED');
        try{
            $whereClause=!is_null($id)?[
                ['owner_id',"=",$request->user()->id],
                ['id',"=",$id],
                ['is_active',"=",1]
            ]:[
                ['owner_id',"=",$request->user()->id],
                ['is_active',"=",1]
            ];
            $fields=['id','tracking_number','amount','total'];
            $shops=Shop::where($whereClause)->get();
            $collection_data=new Collection();
            foreach ($shops as $shop) {
                $data= DB::table('orders')->where('shop_id',$shop->id)->get($fields);
                foreach ($data as $reg)$collection_data->add(new Collection($reg));
            }
            if($collection_data->count()>0) $this->repository->arrayToCsv($collection_data,null,$fields);
        }catch (Exception $ex){
            throw new MarvelException(config('shop.app_notice_domain') . 'ERROR.NOT_FOUND');
        }



    }
}
