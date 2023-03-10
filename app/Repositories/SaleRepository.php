<?php

/** @noinspection ALL */

namespace App\Repositories;

use App\Models\Board;
use App\Models\LocationSaleInformation;
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
        $aggregatedQuery = fn (string $value, string $youWantToDo) => $this->aggregatedQuery(
            $this->querySale(
                $salesmans->pluck('id'),
                $board,
                $unit,
                $salesman,
                $startEndDate
            ),
            $value,
            $youWantToDo
        );

        return [
            'total_sales' => $aggregatedQuery('sale_id', 'count'),
            'sales_amount' => $aggregatedQuery('sale_value', 'sum'),
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

        $requestIp = request()->ip();
        $latitude = $request['latitude'];
        $longitude = $request['longitude'];
        $unitName = null;
        $roaming = $request['roaming'] ?? 0;
        $dateHourSale =  $request['date_hour_sale'] ?? Carbon::now();

        $distance = distance(
            $latitude,
            $longitude,
            $authUser->show_seller_coordinates->latitude,
            $authUser->show_seller_coordinates->longitude
        );

        if ($distance > 100) {

            $relevantUnits = Unity::select('id', 'unit_name', 'latitude', 'longitude')->get();

            $closestUnit = $this->findClosestUnit($latitude, $longitude, $relevantUnits);

            if ($closestUnit !== null && $closestUnit->id != $authUser->board_unit->unit_id) {

                if ($calculateHaversine) {

                    $roaming = 1;
                }

                $unitName = $closestUnit->unit_name;
            }
        }

        $sale = new Sale();
        $sale->user_id = $authUser->id;
        $sale->unit_name = $unitName;
        $sale->latitude = $latitude;
        $sale->longitude = $longitude;
        $sale->sale_value = $request['sale_value'];
        $sale->ip_address = $requestIp;
        $sale->roaming = $roaming;
        $sale->date_hour_sale = $dateHourSale;
        $sale->save();

        $this->saleInformation($sale->id, $requestIp);

        return $this->querySale()->where('sales.id', $sale->id)->first();
    }

    /**
     * @param string $saleId
     * @param string $requestIp
     * @return void
     */
    private function saleInformation(string $saleId, string $requestIp)
    {
        $location = json_decode(file_get_contents("http://ip-api.com/json/{$requestIp}?fields=61439"), true);

        $locationSaleInformation = new LocationSaleInformation();
        $locationSaleInformation->sale_id = $saleId;
        $locationSaleInformation->country = $location['country'] ?? null;
        $locationSaleInformation->region = $location['region'] ?? null;
        $locationSaleInformation->region_name = $location['regionName'] ?? null;
        $locationSaleInformation->city = $location['city'] ?? null;
        $locationSaleInformation->latitude = $location['lat'] ?? null;
        $locationSaleInformation->longitude = $location['lon'] ?? null;
        $locationSaleInformation->save();
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
                $query->havingRaw('nearest_unit IN (?)', [$unit]);
            })
            ->when($salesman, function ($query, $salesman) {
                $query->where('users.name', $salesman);
            })
            ->when($startEndDate, function ($q, $startEndDate) {
                $q->whereBetween('sales.date_hour_sale', $startEndDate);
            });
    }

    /**
     * @param $subQuery
     * @param string $value
     * @param string $youWantToDo
     * @return mixed
     */
    private function aggregatedQuery($subQuery, string $value, string $youWantToDo)
    {
        $subquerySql = $subQuery->toSql();

        return DB::table(DB::raw("($subquerySql) as subquery"))
            ->mergeBindings($subQuery->getQuery())
            ->$youWantToDo("subquery.$value");
    }

    /**
     * Essa fun????o findClosestUnit recebe quatro par??metros:
     *
     *$latitude: um n??mero que representa a latitude de uma posi????o geogr??fica;
     *$longitude: um n??mero que representa a longitude de uma posi????o geogr??fica;
     *$relevantUnits: um array de objetos que cont??m informa????es sobre unidades geogr??ficas;
     *$minDistance: um n??mero que representa a dist??ncia m??nima para uma unidade geogr??fica ser considerada a mais pr??xima. Se esse par??metro n??o for fornecido, ser?? considerado nulo.
     *O objetivo dessa fun????o ?? encontrar a unidade geogr??fica mais pr??xima da posi????o geogr??fica informada (latitude e longitude). Para fazer isso, a fun????o percorre o array $relevantUnits e calcula a dist??ncia entre a posi????o geogr??fica informada e a posi????o geogr??fica de cada unidade. A dist??ncia ?? calculada por meio de uma chamada ?? fun????o distance, que deve ser definida em algum lugar do c??digo (n??o est?? presente na fun????o fornecida).
     *A cada itera????o do loop, a fun????o verifica se a dist??ncia calculada ?? menor do que a dist??ncia m??nima ($minDistance). Se $minDistance for nulo ou se a dist??ncia atual for menor do que $minDistance, a unidade atual ?? considerada a mais pr??xima e a dist??ncia atual se torna a nova dist??ncia m??nima. Ao final do loop, a fun????o retorna a unidade mais pr??xima encontrada.
     *
     * @param $latitude
     * @param $longitude
     * @param $relevantUnits
     * @param $minDistance
     * @return mixed|null
     */
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
