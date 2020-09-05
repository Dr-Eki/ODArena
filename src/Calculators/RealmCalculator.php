<?php

namespace OpenDominion\Calculators;


use DB;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\Realm;
use OpenDominion\Calculators\Dominion\ProductionCalculator;

class RealmCalculator
{

    /** @var ProductionCalculator */
    protected $productionCalculator;

    /**
     * NetworthCalculator constructor.
     *
     * @param ProductionCalculator $productionCalculator
     */
    public function __construct(
        ProductionCalculator $productionCalculator
    ) {
        $this->productionCalculator = $productionCalculator;
    }

    /**
     * Checks if Realm has a monster.
     *
     * @param Realm $realm
     * @return int
     */
     public function hasMonster(Realm $realm): bool
     {
          $monster_dominion_id = DB::table('dominions')
                         ->join('races', 'dominions.race_id', '=', 'races.id')
                         ->join('realms', 'realms.id', '=', 'dominions.realm_id')
                         ->select('dominions.id')
                         ->where('dominions.round_id', '=', $realm->round->id)
                         ->where('realms.id', '=', $realm->id)
                         ->where('races.name', '=', 'Monster')
                         ->where('dominions.protection_ticks', '=', 0)
                         ->pluck('dominions.id')->first();

          if($monster_dominion_id === null)
          {
            return false;
          }

         return $monster_dominion_id;
     }

    public function getMonster(Realm $realm): Dominion
    {
        $monster = DB::table('dominions')
                        ->join('races', 'dominions.race_id', '=', 'races.id')
                        ->join('realms', 'realms.id', '=', 'dominions.realm_id')
                        ->select('dominions.id')
                        ->where('dominions.round_id', '=', $realm->round->id)
                        ->where('realms.id', '=', $realm->id)
                        ->where('races.name', '=', 'Monster')
                        ->groupBy('realms.alignment')
                        ->pluck('dominions.id')->first();

        $monster = Dominion::findOrFail($monster);

        return $monster;
    }

    public function getTotalContributions(Realm $realm): array
    {
        $contributions = [
            'food' => 0,
            'lumber' => 0,
            'ore' => 0
          ];

        if($this->hasMonster($realm))
        {
            $dominions = $realm->dominions->flatten();

            foreach($contributions as $resource => $amount)
            {
                foreach($dominions as $dominion)
                {
                    #echo '<p>' . $dominion->name . ' contributes ' . $this->productionCalculator->getContribution($dominion, $resource) . ' ' . $resource . '</p>';
                    $contributions[$resource] += $this->productionCalculator->getContribution($dominion, $resource);
                }
            }
        }
        return $contributions;
    }



}
