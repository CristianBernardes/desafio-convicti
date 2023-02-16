<?php

/** @noinspection ALL */

namespace App\Repositories;

use App\Models\Board;
use App\Models\Unity;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 *
 */
class SaleRepository
{
    /**
     * @param $authUser
     * @param string|null $board
     * @param string|null $unit
     * @param string|null $salesman
     * @param array|null $startEndDate
     * @return array
     */
    public function getSales($authUser, string|null $board, string|null $unit, string|null $salesman, array|null $startEndDate)
    {
        $boardsUnits = $authUser->board_unit;

        $boards = $units = $salesmans = collect();

        switch ($authUser->profile) {
            case User::GENERAL_MANAGER:
                $boards = Board::select('board_name')->get()->pluck('board_name');
                $units = Unity::select('unit_name')->get()->pluck('unit_name');
                $salesmans = User::select('id', 'name')->get();
                break;

            case User::DIRECTOR:
                $boards = Board::select('board_name')->where('id', $boardsUnits['board_id'])->get()->pluck('board_name');
                $units = Unity::select('unit_name')->where('board_id', $boardsUnits['board_id'])->get()->pluck('unit_name');
                $salesmans = User::select('users.id', 'users.name')
                    ->join('board_unit_users', 'users.id', 'board_unit_users.user_id')
                    ->where('board_id', $boardsUnits['board_id'])
                    ->get();
                break;

            case User::MANAGER:
                $boards = Board::select('board_name')->where('id', $boardsUnits['board_id'])->get()->pluck('board_name');
                $units = Unity::select('unit_name')->where('id', $boardsUnits['unit_id'])->get()->pluck('unit_name');
                $salesmans = User::select('users.id', 'users.name')
                    ->join('board_unit_users', 'users.id', 'board_unit_users.user_id')
                    ->where('unit_id', $boardsUnits['unit_id'])
                    ->get();
                break;

            case User::SALESMAN:
                $boards = Board::select('board_name')->where('id', $boardsUnits['board_id'])->get()->pluck('board_name');
                $units = Unity::select('unit_name')->where('id', $boardsUnits['unit_id'])->get()->pluck('unit_name');
                $salesmans = User::select('users.id', 'users.name')
                    ->join('board_unit_users', 'users.id', 'board_unit_users.user_id')
                    ->where('user_id', $authUser->id)
                    ->get();
                break;

            default:
                break;
        }

        /** @var TYPE_NAME $aggregatedQuery */
        $aggregatedQuery = fn (string $value) => $this->aggregatedQuery(
            $this->querySale(
                $salesmans->pluck('id'),
                $board,
                $unit,
                $salesman,
                $startEndDate
            ),
            $value
        );

        return [
            'sales_amount' => $aggregatedQuery('sale_value'),
            'sales' => $this->querySale($salesmans->pluck('id'), $board, $unit, $salesman, $startEndDate)->get(),
            'menu' => [
                'boards' => $boards,
                'units' => $units,
                'salesman' => $salesmans->pluck('name'),
            ]
        ];
    }

    /**
     * @param string $saleId
     * @return mixed
     * @throws \Exception
     */
    public function getSale($authUser, string $saleId)
    {
        $boardsUnits = $authUser->board_unit;

        $query = $this->querySale();

        switch ($authUser->profile) {

            case User::GENERAL_MANAGER:
                break;

            case User::DIRECTOR:
                $query->where('boards.id', $boardsUnits['board_id']);
                break;

            case User::MANAGER:
                $query->where('boards.id', $boardsUnits['board_id'])
                    ->where('units.id', $boardsUnits['unit_id']);
                break;

            case User::SALESMAN:
                $query->where('users.id', $authUser->id);
                break;

            default:
                throw new \Exception('Invalid user profile', 403);
        }

        $sale = $query->where('sales.id', $saleId)->first();

        if (!$sale) {
            throw new \Exception('Sale not found', 404);
        }

        return $sale;
    }

    /**
     * @param $usersId
     * @param $board
     * @param $unit
     * @param $salesman
     * @param $startEndDate
     * @return mixed
     */
    private function querySale(
        $usersId = null,
        $board = null,
        $unit = null,
        $salesman = null,
        $startEndDate = null,
    ) {
        return Sale::select(
            'sales.id AS sale_id',
            'sales.sale_value',
            'users.name AS salesman',
            DB::raw('COALESCE(sales.unit_name, units.unit_name) AS nearest_unit'),
            'boards.board_name AS board_salesman',
            'sales.latitude',
            'sales.longitude',
            'sales.roaming',
        )
            ->join('users', 'users.id', 'sales.user_id')
            ->join('board_unit_users', 'board_unit_users.user_id', 'users.id')
            ->join('units', 'units.id', 'board_unit_users.unit_id')
            ->join('boards', 'boards.id', 'units.board_id')
            ->when($usersId, function ($query, $usersId) {
                $query->whereIn('sales.user_id', $usersId);
            })
            ->when($board, function ($query, $board) {
                $query->where('boards.board_name', $board);
            })
            ->when($unit, function ($query, $unit) {
                $query->where('units.unit_name', $unit);
            })
            ->when($salesman, function ($query, $salesman) {
                $query->where('users.name', $salesman);
            })->when($startEndDate, function ($q, $startEndDate) {
                $q->whereBetween('sales.date_hour_sale', $startEndDate);
            });
    }

    /**
     * @param $authUser
     * @param array $request
     * @return Sale
     * @throws \Exception
     */
    public function insertSale($authUser, array $request, bool $calculateHaversine = true)
    {
        if ($authUser->profile != User::SALESMAN) {
            throw new \Exception('Only sellers are allowed to make a sale', 403);
        }

        $latitude = $request['latitude'];
        $longitude = $request['longitude'];
        $unitName = null;
        $roaming = $calculateHaversine ? 0 : $request['roaming'];

        $distance = distance($latitude, $longitude, $authUser->show_seller_coordinates->latitude, $authUser->show_seller_coordinates->longitude);

        if ($distance > 100 && $calculateHaversine) {
            $relevantUnits = Unity::select('unit_name', 'latitude', 'longitude')->get();

            $closestUnit = $this->findClosestUnit($latitude, $longitude, $relevantUnits);

            if ($closestUnit !== null) {
                $roaming = 1;
                $unitName = $closestUnit->unit_name;
            }
        }

        $sale = new Sale();
        $sale->user_id = $authUser->id;
        $sale->unit_name = $unitName;
        $sale->latitude = $latitude;
        $sale->longitude = $longitude;
        $sale->sale_value = $request['sale_value'];
        $sale->roaming = $roaming;
        $sale->date_hour_sale = Carbon::now();
        $sale->save();

        return $this->querySale()->where('sales.id', $sale->id)->first();
    }

    /**
     * @param $subQuery
     * @param $value
     * @return int|mixed
     */
    private function aggregatedQuery($subQuery, $value)
    {
        $subquerySql = $subQuery->toSql();

        return DB::table(DB::raw("($subquerySql) as subquery"))
            ->mergeBindings($subQuery->getQuery())
            ->sum("subquery.$value");
    }

    private function findClosestUnit($latitude, $longitude, $relevantUnits, $minDistance = null)
    {
        $minUnit = null;

        foreach ($relevantUnits as $unit) {
            $distance = distance($latitude, $longitude, $unit->latitude, $unit->longitude);

            if ($minDistance === null || $distance < $minDistance) {
                $minUnit = $unit;
                $minDistance = $distance;
            }
        }

        return $minUnit;
    }
}
