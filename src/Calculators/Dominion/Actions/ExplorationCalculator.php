<?php

namespace OpenDominion\Calculators\Dominion\Actions;

use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\ImprovementCalculator;
use OpenDominion\Models\Dominion;
use OpenDominion\Services\Dominion\GuardMembershipService;

# ODA
use OpenDominion\Calculators\Dominion\SpellCalculator;

class ExplorationCalculator
{
    /** @var LandCalculator */
    protected $landCalculator;

    /** @var ImprovementCalculator */
    protected $improvementCalculator;

    /** @var GuardMembershipService */
    protected $guardMembershipService;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /**
     * ExplorationCalculator constructor.
     *
     * @param LandCalculator $landCalculator
     * @param GuardMembershipService $guardMembershipService
     */
    public function __construct(
        LandCalculator $landCalculator,
        GuardMembershipService $guardMembershipService,
        SpellCalculator $spellCalculator,
        ImprovementCalculator $improvementCalculator)
    {
        $this->landCalculator = $landCalculator;
        $this->guardMembershipService = $guardMembershipService;
        $this->spellCalculator = $spellCalculator;
        $this->improvementCalculator = $improvementCalculator;
    }

    /**
     * Returns the Dominion's exploration platinum cost (raw).
     *
     * @param Dominion $dominion
     * @return int
     */
     public function getPlatinumCostRaw(Dominion $dominion): int
     {
       $platinum = 0;
       $totalLand = $this->landCalculator->getTotalLand($dominion);

       if ($totalLand < 300) {
           $platinum += -(3 * (300 - $totalLand));
       } else {
           $exponent = ($totalLand ** 0.0185) / 1.05;
           $exponent = clamp($exponent, 1.09, 1.121);
           $platinum += (3 * (($totalLand - 300) ** $exponent));
       }

       $platinum += 1000;

       return $platinum;
     }

     /**
      * Returns the Dominion's exploration platinum cost bonus.
      *
      * @param Dominion $dominion
      * @return int
      */
      public function getPlatinumCostBonus(Dominion $dominion): float
      {
        $multiplier = 0;

        // Techs
        $multiplier = $dominion->getTechPerkMultiplier('explore_platinum_cost');

        // Racial bonus
        $multiplier += $dominion->race->getPerkMultiplier('explore_cost');

        // Improvement: Cartography
        $multiplier -= $this->improvementCalculator->getImprovementMultiplierBonus($dominion, 'cartography');

        // Elite Guard Tax
        if ($this->guardMembershipService->isEliteGuardMember($dominion))
        {
            $multiplier += 0.25;
        }


        # Cap explore plat reduction to 50%.
        $multiplier = min($multiplier,0.50);

        return (1 + $multiplier);

      }

   /**
    * Returns the Dominion's exploration platinum cost.
    *
    * @param Dominion $dominion
    * @return int
    */
    public function getPlatinumCost(Dominion $dominion): int
    {
      return $this->getPlatinumCostRaw($dominion) * $this->getPlatinumCostBonus($dominion);
    }

    /**
     * Returns the Dominion's exploration draftee cost (raw).
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getDrafteeCostRaw(Dominion $dominion): int
    {
        $draftees = 0;
        $totalLand = $this->landCalculator->getTotalLand($dominion);

        if ($totalLand < 300) {
            $draftees = -(300 / $totalLand);
        } else {
            $draftees += (0.003 * (($totalLand - 300) ** 1.07));
        }

        $draftees += 5;

        return $draftees;
    }

    /**
     * Returns the Dominion's exploration draftee cost modifier.
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getDrafteeCostModifier(Dominion $dominion): int
    {
        $modifier = 0;
        // Techs
        $modifier += $dominion->getTechPerkValue('explore_draftee_cost');

        return round($modifier);
    }

    /**
     * Returns the Dominion's exploration platinum cost.
     *
     * @param Dominion $dominion
     * @return int
     */
     public function getDrafteeCost(Dominion $dominion): int
     {
       return max(3, $this->getDrafteeCostRaw($dominion) + $this->getDrafteeCostModifier($dominion));
     }

    /**
     * Returns the maximum number of acres of land a Dominion can afford to
     * explore.
     *
     * @param Dominion $dominion
     * @return int
     */
    public function getMaxAfford(Dominion $dominion): int
    {
        return min(
            floor($dominion->resource_platinum / $this->getPlatinumCost($dominion)),
            floor($dominion->military_draftees / $this->getDrafteeCost($dominion)),
            floor($this->landCalculator->getTotalLand($dominion) * (($dominion->morale/100)/8))
        );
    }

    /**
     * Returns the morale drop after exploring for $amount of acres of land.
     *
     * @param int $amount
     * @return int
     * @todo Does this really belong here? Maybe it should go in a helper, since it isn't dependent on a Dominion instance
     */
    public function getMoraleDrop($dominion, $amountToExplore): int
    {
        $moraleDrop = ($amountToExplore / $this->landCalculator->getTotalLand($dominion)) * 8 * 100;

        return max($moraleDrop, 1);

        #return floor(($amount + 2) / 3);
    }
}
