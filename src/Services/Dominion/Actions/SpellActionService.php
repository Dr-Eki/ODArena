<?php

namespace OpenDominion\Services\Dominion\Actions;

use DB;
use Exception;
use LogicException;
use OpenDominion\Calculators\Dominion\ImprovementCalculator;
use OpenDominion\Calculators\Dominion\LandCalculator;
use OpenDominion\Calculators\Dominion\MilitaryCalculator;
use OpenDominion\Calculators\Dominion\PopulationCalculator;
use OpenDominion\Calculators\Dominion\RangeCalculator;
use OpenDominion\Calculators\Dominion\SpellCalculator;
use OpenDominion\Calculators\NetworthCalculator;
use OpenDominion\Exceptions\GameException;
use OpenDominion\Helpers\OpsHelper;
use OpenDominion\Helpers\SpellHelper;
use OpenDominion\Models\Dominion;
use OpenDominion\Models\InfoOp;
use OpenDominion\Services\Dominion\HistoryService;
use OpenDominion\Services\Dominion\ProtectionService;
use OpenDominion\Services\Dominion\QueueService;
use OpenDominion\Services\NotificationService;
use OpenDominion\Traits\DominionGuardsTrait;

# ODA
use OpenDominion\Models\BlackOp;

class SpellActionService
{
    use DominionGuardsTrait;

    /**
     * @var float Hostile ops base success rate
     */
    protected const HOSTILE_MULTIPLIER_SUCCESS_RATE = 2;

    /**
     * @var float Info op base success rate
     */
    protected const INFO_MULTIPLIER_SUCCESS_RATE = 1.4;

    /** @var ImprovementCalculator */
    protected $improvementCalculator;

    /** @var LandCalculator */
    protected $landCalculator;

    /** @var MilitaryCalculator */
    protected $militaryCalculator;

    /** @var NetworthCalculator */
    protected $networthCalculator;

    /** @var NotificationService */
    protected $notificationService;

    /** @var OpsHelper */
    protected $opsHelper;

    /** @var PopulationCalculator */
    protected $populationCalculator;

    /** @var ProtectionService */
    protected $protectionService;

    /** @var QueueService */
    protected $queueService;

    /** @var RangeCalculator */
    protected $rangeCalculator;

    /** @var SpellCalculator */
    protected $spellCalculator;

    /** @var SpellHelper */
    protected $spellHelper;

    /**
     * SpellActionService constructor.
     */
    public function __construct()
    {
        $this->improvementCalculator = app(ImprovementCalculator::class);
        $this->landCalculator = app(LandCalculator::class);
        $this->militaryCalculator = app(MilitaryCalculator::class);
        $this->networthCalculator = app(NetworthCalculator::class);
        $this->notificationService = app(NotificationService::class);
        $this->opsHelper = app(OpsHelper::class);
        $this->populationCalculator = app(PopulationCalculator::class);
        $this->protectionService = app(ProtectionService::class);
        $this->queueService = app(QueueService::class);
        $this->rangeCalculator = app(RangeCalculator::class);
        $this->spellCalculator = app(SpellCalculator::class);
        $this->spellHelper = app(SpellHelper::class);
    }

    public const BLACK_OPS_DAYS_AFTER_ROUND_START = 1;

    /**
     * Casts a magic spell for a dominion, optionally aimed at another dominion.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param null|Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    public function castSpell(Dominion $dominion, string $spellKey, ?Dominion $target = null, bool $isInvasionSpell = false): array
    {
        $this->guardLockedDominion($dominion);
        if ($target !== null) {
            $this->guardLockedDominion($target);
        }

        $spellInfo = $this->spellHelper->getSpellInfo($spellKey, $dominion, $isInvasionSpell, false);

        if (!$spellInfo) {
            throw new LogicException("Cannot cast unknown spell '{$spellKey}'");
        }

        if ($dominion->wizard_strength <= 0) {
            throw new GameException("Your wizards to not have enough strength to cast {$spellInfo['name']}.");
        }

        $manaCost = $this->spellCalculator->getManaCost($dominion, $spellKey, $isInvasionSpell);

        if ($dominion->resource_mana < $manaCost) {
            throw new GameException("You do not have enough mana to cast {$spellInfo['name']}.");
        }

        if ($this->spellCalculator->isOnCooldown($dominion, $spellKey, $isInvasionSpell)) {
            throw new GameException("You can only cast {$spellInfo['name']} every {$spellInfo['cooldown']} hours.");
        }

        if ($this->spellHelper->isOffensiveSpell($spellKey, $dominion)) {
            if ($target === null) {
                throw new GameException("You must select a target when casting offensive spell {$spellInfo['name']}");
            }

            if ($this->protectionService->isUnderProtection($dominion)) {
                throw new GameException('You cannot cast offensive spells while under protection');
            }

            if ($this->protectionService->isUnderProtection($target)) {
                throw new GameException('You cannot cast offensive spells to targets which are under protection');
            }

            if (!$this->rangeCalculator->isInRange($dominion, $target)) {
                throw new GameException('You cannot cast offensive spells to targets outside of your range');
            }

            if ($dominion->round->id !== $target->round->id) {
                throw new GameException('Nice try, but you cannot cast spells cross-round');
            }

            if($dominion->race->alignment == 'good')
            {
              if ($dominion->realm->id === $target->realm->id) {
                  throw new GameException('Nice try, but you cannot cast spells on your realmies');
              }
            }
        }

        $result = null;

        DB::transaction(function () use ($dominion, $manaCost, $spellKey, &$result, $target, $isInvasionSpell) {
            if ($this->spellHelper->isSelfSpell($spellKey, $dominion)) {
                $result = $this->castSelfSpell($dominion, $spellKey);

            } elseif ($this->spellHelper->isInfoOpSpell($spellKey)) {
                $result = $this->castInfoOpSpell($dominion, $spellKey, $target);

            } elseif ($this->spellHelper->isHostileSpell($spellKey, $dominion, $isInvasionSpell)) {
                $result = $this->castHostileSpell($dominion, $spellKey, $target, $isInvasionSpell);

            } else {
                throw new LogicException("Unknown type for spell {$spellKey}");
            }

            if(!$isInvasionSpell)
            {
              $dominion->resource_mana -= $manaCost;

              $wizardStrengthLost = $result['wizardStrengthCost'] ?? 5;
              $wizardStrengthLost = min($wizardStrengthLost, $dominion->wizard_strength);
              $dominion->wizard_strength -= $wizardStrengthLost;

              # XP Gained.
              if($result['success'] == True and isset($result['damage']))
              {
                $xpGained = $this->calculateXpGain($dominion, $target, $result['damage']);
                $dominion->resource_tech += $xpGained;
              }

              if (!$this->spellHelper->isSelfSpell($spellKey, $dominion))
              {
                  $dominion->stat_spell_success += 1;
              }
            }

            $dominion->save([
                'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                'action' => $spellKey
            ]);
        });

        if ($target !== null) {
            $this->rangeCalculator->checkGuardApplications($dominion, $target);
        }

        return [
                'message' => $result['message'], /* sprintf(
                    $this->getReturnMessageString($dominion), // todo
                    $spellInfo['name'],
                    number_format($manaCost)
                ),*/
                'data' => [
                    'spell' => $spellKey,
                    'manaCost' => $manaCost,
                ],
                'redirect' =>
                    $this->spellHelper->isInfoOpSpell($spellKey) && $result['success']
                        ? $result['redirect']
                        : null,
            ] + $result;
    }

    /**
     * Casts a self spell for $dominion.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castSelfSpell(Dominion $dominion, string $spellKey): array
    {
        $spellInfo = $this->spellHelper->getSpellInfo($spellKey, $dominion);

        if ($this->spellCalculator->isSpellActive($dominion, $spellKey)) {

            $where = [
                'dominion_id' => $dominion->id,
                'spell' => $spellKey,
            ];

            $activeSpell = DB::table('active_spells')
                ->where($where)
                ->first();

            if ($activeSpell === null) {
                throw new LogicException("Active spell '{$spellKey}' for dominion id {$dominion->id} not found");
            }

            if ((int)$activeSpell->duration === $spellInfo['duration']) {
                throw new GameException("Your wizards refused to recast {$spellInfo['name']}, since it is already at maximum duration.");
            }

            DB::table('active_spells')
                ->where($where)
                ->update([
                    'duration' => $spellInfo['duration'],
                    'updated_at' => now(),
                ]);

        } else {

            DB::table('active_spells')
                ->insert([
                    'dominion_id' => $dominion->id,
                    'spell' => $spellKey,
                    'duration' => $spellInfo['duration'],
                    'cast_by_dominion_id' => $dominion->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

        }

        return [
            'success' => true,
            'message' => sprintf(
                'Your wizards cast the spell successfully, and it will continue to affect your dominion for the next %s ticks.',
                $spellInfo['duration']
            )
        ];
    }

    /**
     * Casts an info op spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws Exception
     */
    protected function castInfoOpSpell(Dominion $dominion, string $spellKey, Dominion $target): array
    {
        $spellInfo = $this->spellHelper->getSpellInfo($spellKey, $dominion);

        $selfWpa = $this->militaryCalculator->getWizardRatio($dominion, 'offense');
        $targetWpa = $this->militaryCalculator->getWizardRatio($target, 'defense');

        // You need at least some positive WPA to cast info ops
        if ($selfWpa === 0.0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spellInfo['name']}. Please train more wizards.");
        }

        // 100% spell success if target has a WPA of 0
        if ($targetWpa !== 0.0) {
            $successRate = $this->opsHelper->operationSuccessChance($selfWpa, $targetWpa,
                static::INFO_MULTIPLIER_SUCCESS_RATE);

            if (!random_chance($successRate)) {
                // Inform target that they repelled a hostile spell
                $this->notificationService
                    ->queueNotification('repelled_hostile_spell', [
                        'sourceDominionId' => $dominion->id,
                        'spellKey' => $spellKey,
                        'unitsKilled' => '',
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                // Return here, thus completing the spell cast and reducing the caster's mana
                return [
                    'success' => false,
                    'message' => "The enemy wizards have repelled our {$spellInfo['name']} attempt.",
                    'wizardStrengthCost' => 2,
                    'alert-type' => 'warning',
                ];
            }
        }

        $infoOp = new InfoOp([
            'source_realm_id' => $dominion->realm->id,
            'target_realm_id' => $target->realm->id,
            'type' => $spellKey,
            'source_dominion_id' => $dominion->id,
            'target_dominion_id' => $target->id,
        ]);

        switch ($spellKey) {
            case 'clear_sight':
                $infoOp->data = [

                    'ruler_name' => $target->ruler_name,
                    'race_id' => $target->race->id,
                    'land' => $this->landCalculator->getTotalLand($target),
                    'peasants' => $target->peasants,
                    'employment' => $this->populationCalculator->getEmploymentPercentage($target),
                    'networth' => $this->networthCalculator->getDominionNetworth($target),
                    'prestige' => $target->prestige,
                    'victories' => $target->stat_attacking_success,

                    'resource_platinum' => $target->resource_platinum,
                    'resource_food' => $target->resource_food,
                    'resource_lumber' => $target->resource_lumber,
                    'resource_mana' => $target->resource_mana,
                    'resource_ore' => $target->resource_ore,
                    'resource_gems' => $target->resource_gems,
                    'resource_tech' => $target->resource_tech,
                    'resource_boats' => $target->resource_boats + $this->queueService->getInvasionQueueTotalByResource(
                            $target,
                            'resource_boats'
                        ),


                    'resource_champion' => $target->resource_champion,
                    'resource_soul' => $target->resource_soul,
                    'resource_wild_yeti' => $target->resource_wild_yeti,

                    'morale' => $target->morale,
                    'military_draftees' => $target->military_draftees,
                    'military_unit1' => $this->militaryCalculator->getTotalUnitsForSlot($target, 1),
                    'military_unit2' => $this->militaryCalculator->getTotalUnitsForSlot($target, 2),
                    'military_unit3' => $this->militaryCalculator->getTotalUnitsForSlot($target, 3),
                    'military_unit4' => $this->militaryCalculator->getTotalUnitsForSlot($target, 4),
                    'military_spies' => $target->military_spies,
                    'military_wizards' => $target->military_wizards,
                    'military_archmages' => $target->military_archmages,

                    'recently_invaded_count' => $this->militaryCalculator->getRecentlyInvadedCount($target),

                ];
                break;

            case 'vision':
                $infoOp->data = [
                    'techs' => $target->techs->pluck('name', 'key')->all(),
                    'heroes' => []
                ];
                break;

            case 'revelation':
                $infoOp->data = $this->spellCalculator->getActiveSpells($target);
                break;

            case 'clairvoyance':
                $infoOp->data = [
                    'targetRealmId' => $target->realm->id
                ];
                break;

//            case 'disclosure':
//                $infoOp->data = [];
//                break;

            default:
                throw new LogicException("Unknown info op spell {$spellKey}");
        }

        $infoOp->save();

        $redirect = route('dominion.op-center.show', $target);
        if ($spellKey === 'clairvoyance') {
            $redirect = route('dominion.op-center.clairvoyance', $target->realm->number);
        }

        return [
            'success' => true,
            'message' => 'Your wizards cast the spell successfully, and a wealth of information appears before you.',
            'wizardStrengthCost' => 2,
            'redirect' => $redirect,
        ];
    }

    /**
     * Casts a hostile spell for $dominion to $target.
     *
     * @param Dominion $dominion
     * @param string $spellKey
     * @param Dominion $target
     * @return array
     * @throws GameException
     * @throws LogicException
     */
    protected function castHostileSpell(Dominion $dominion, string $spellKey, Dominion $target, bool $isInvasionSpell = false): array
    {
        if ($dominion->round->hasOffensiveActionsDisabled()) {
            throw new GameException('Black ops have been disabled for the remainder of the round.');
        }

        if (now()->diffInDays($dominion->round->start_date) < self::BLACK_OPS_DAYS_AFTER_ROUND_START and !$isInvasionSpell)
        {
            throw new GameException('You cannot perform black ops for the first day of the round');
        }

        $spellInfo = $this->spellHelper->getSpellInfo($spellKey, $dominion, $isInvasionSpell, false);

        if ($this->spellHelper->isWarSpell($spellKey, $dominion)) {
            $warDeclared = ($dominion->realm->war_realm_id == $target->realm->id || $target->realm->war_realm_id == $dominion->realm->id);
            if (!$warDeclared && !$this->militaryCalculator->recentlyInvadedBy($dominion, $target)) {
                throw new GameException("You cannot cast {$spellInfo['name']} outside of war.");
            }
        }

        # For invasion spell, target WPA is 0.
        if(!$isInvasionSpell)
        {
          $selfWpa = min(10,$this->militaryCalculator->getWizardRatio($dominion, 'offense'));
          $targetWpa = min(10,$this->militaryCalculator->getWizardRatio($target, 'defense'));
        }
        else
        {
          $selfWpa = 10;
          $targetWpa = 0;
        }

        // You need at least some positive WPA to cast info ops
        if ($selfWpa === 0.0) {
            // Don't reduce mana by throwing an exception here
            throw new GameException("Your wizard force is too weak to cast {$spellInfo['name']}. Please train more wizards.");
        }

        // 100% spell success if target has a WPA of 0
        if ($targetWpa !== 0.0)
        {
            $successRate = $this->opsHelper->operationSuccessChance($selfWpa, $targetWpa,
                static::HOSTILE_MULTIPLIER_SUCCESS_RATE, $isInvasionSpell);

            if (!random_chance($successRate)) {
                $wizardsKilledBasePercentage = 1;

                $wizardLossSpaRatio = ($targetWpa / $selfWpa);
                $wizardsKilledPercentage = clamp($wizardsKilledBasePercentage * $wizardLossSpaRatio, 0.5, 1.5);

                $unitsKilled = [];
                $wizardsKilled = (int)floor($dominion->military_wizards * ($wizardsKilledPercentage / 100));

                // Check for immortal wizards
                if ($dominion->race->getPerkValue('immortal_wizards') != 0)
                {
                    $wizardsKilled = 0;
                }

                if ($wizardsKilled > 0)
                {
                    $unitsKilled['wizards'] = $wizardsKilled;
                    $dominion->military_wizards -= $wizardsKilled;
                }

                foreach ($dominion->race->units as $unit)
                {
                    if ($unit->getPerkValue('counts_as_wizard_offense'))
                    {
                        if($unit->getPerkValue('immortal_wizard'))
                        {
                          $unitKilled = 0;
                        }
                        else
                        {
                          $unitKilledMultiplier = ((float)$unit->getPerkValue('counts_as_wizard_offense') / 2) * ($wizardsKilledPercentage / 100);
                          $unitKilled = (int)floor($dominion->{"military_unit{$unit->slot}"} * $unitKilledMultiplier);
                        }

                        if ($unitKilled > 0)
                        {
                            $unitsKilled[strtolower($unit->name)] = $unitKilled;
                            $dominion->{"military_unit{$unit->slot}"} -= $unitKilled;
                        }
                    }
                }

                $unitsKilledStringParts = [];
                foreach ($unitsKilled as $name => $amount) {
                    $amountLabel = number_format($amount);
                    $unitLabel = str_plural(str_singular($name), $amount);
                    $unitsKilledStringParts[] = "{$amountLabel} {$unitLabel}";
                }
                $unitsKilledString = generate_sentence_from_array($unitsKilledStringParts);

                // Inform target that they repelled a hostile spell
                $this->notificationService
                    ->queueNotification('repelled_hostile_spell', [
                        'sourceDominionId' => $dominion->id,
                        'spellKey' => $spellKey,
                        'unitsKilled' => $unitsKilledString,
                    ])
                    ->sendNotifications($target, 'irregular_dominion');

                if ($unitsKilledString) {
                    $message = "The enemy wizards have repelled our {$spellInfo['name']} attempt and managed to kill $unitsKilledString.";
                } else {
                    $message = "The enemy wizards have repelled our {$spellInfo['name']} attempt.";
                }

                // Return here, thus completing the spell cast and reducing the caster's mana
                return [
                    'success' => false,
                    'message' => $message,
                    'wizardStrengthCost' => 2,
                    'alert-type' => 'warning',
                ];
            }
        }

        $spellDeflected = false;
        if ($this->spellCalculator->isSpellActive($target, 'energy_mirror') && random_chance(0.2) and !$isInvasionSpell)
        {
            $spellDeflected = true;
            $deflectedBy = $target;
            $target = $dominion;
            $dominion = $deflectedBy;
        }

        if (isset($spellInfo['duration']))
        {
            // Cast spell with duration
            if ($this->spellCalculator->isSpellActive($target, $spellKey)) {
                $where = [
                    'dominion_id' => $target->id,
                    'spell' => $spellKey,
                ];

                $activeSpell = DB::table('active_spells')
                    ->where($where)
                    ->first();

                if ($activeSpell === null) {
                    throw new LogicException("Active spell '{$spellKey}' for dominion id {$target->id} not found");
                }

                DB::table('active_spells')
                    ->where($where)
                    ->update([
                        'duration' => $spellInfo['duration'],
                        'cast_by_dominion_id' => $dominion->id,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('active_spells')
                    ->insert([
                        'dominion_id' => $target->id,
                        'spell' => $spellKey,
                        'duration' => $spellInfo['duration'],
                        'cast_by_dominion_id' => $dominion->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // Update statistics
            if (isset($dominion->{"stat_{$spellInfo['key']}_hours"})) {
                $dominion->{"stat_{$spellInfo['key']}_hours"} += $spellInfo['duration'];
            }

            // Surreal Perception
            $sourceDominionId = null;
            if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spellKey,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellDeflected) {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was deflected and it will now affect your dominion for the next %s hours.',
                        $spellInfo['duration']
                    ),
                    'alert-type' => 'danger'
                ];
            } else {
                return [
                    'success' => true,
                    'damage' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, and it will continue to affect your target for the next %s hours.',
                        $spellInfo['duration']
                    )
                ];
            }
        }
        else
        {
            // Cast spell instantly
            $damageDealt = [];
            $totalDamage = 0;
            $baseDamage = (isset($spellInfo['percentage']) ? $spellInfo['percentage'] : 1) / 100;

            # Calculate ratio differential.
            $baseDamageMultiplier = (1 + ($selfWpa - $targetWpa) / 10);

            $baseDamage *= $baseDamageMultiplier;

            if (isset($spellInfo['decreases']))
            {
                foreach ($spellInfo['decreases'] as $attr)
                {
                    $damage = $target->{$attr} * $baseDamage;
                    $damageMultiplier = 0;

                    // Damage reduction from Forest Havens and racial perk
                    if ($attr == 'peasants')
                    {

                      $forestHavenFireballReduction = 8;
                      $forestHavenFireballReductionMax = 0.80;
                          $damageMultiplier -= min(
                            (($target->building_forest_haven / $this->landCalculator->getTotalLand($target)) * $forestHavenFireballReduction),
                            ($forestHavenFireballReductionMax)
                        );

                        if($target->race->getPerkMultiplier('damage_from_fireballs'))
                        {
                          $damageMultiplier -= $target->race->getPerkMultiplier('damage_from_fireballs');
                        }
                    }

                    // Damage reduction from Masonries and racial perk
                    if (strpos($attr, 'improvement_') === 0)
                    {
                        $masonryLightningBoltReduction = 0.75;
                        $masonryLightningBoltReductionMax = 0.25;
                        $damageMultiplier -= min(
                            (($target->building_masonry / $this->landCalculator->getTotalLand($target)) * $masonryLightningBoltReduction),
                            ($masonryLightningBoltReductionMax)
                        );


                        if($target->race->getPerkMultiplier('damage_from_lightning_bolts'))
                        {
                          $damageMultiplier -= $target->race->getPerkMultiplier('damage_from_lightning_bolts');
                        }
                    }

                    // Check for immortal spies
                    if ($dominion->race->getPerkValue('immortal_spies') != 0 && $attr == 'military_spies')
                    {
                        $damage = 0;
                    }

                    // Special for Purification
                    if($spellInfo['name'] == 'Purification')
                    {
                      if($target->race->name !== 'Afflicted')
                      {
                        $damage = 0;
                      }
                    }

                    // Damage reduction from Towers
                    $damageMultiplier -= $this->improvementCalculator->getImprovementMultiplierBonus($target, 'towers');

                    // Cap the damage multiplier at -1
                    $damageMultiplier = max(-1, $damageMultiplier);

                    $damage = $damage * (1-$damageMultiplier);

                    $totalDamage += round($damage);
                    $target->{$attr} -= round($damage);
                    $damageDealt[] = sprintf('%s %s', number_format($damage), dominion_attr_display($attr, $damage));

                    // Update statistics
                    if (isset($dominion->{"stat_{$spellInfo['key']}_damage"})) {
                        // Only count peasants killed by fireball
                        if (!($spellInfo['key'] == 'fireball' && $attr == 'resource_food')) {
                            $dominion->{"stat_{$spellInfo['key']}_damage"} += round($damage);
                        }
                    }
                }

                // Combine lightning bolt damage into single string
                if ($spellInfo['key'] === 'lightning_bolt') {
                    // Combine lightning bold damage into single string
                    $damageDealt = [sprintf('%s %s', number_format($totalDamage), dominion_attr_display('improvement', $totalDamage))];
                }
            }

            if (isset($spellInfo['increases']))
            {
                foreach ($spellInfo['increases'] as $attr)
                {
                    $damage = $target->{$attr} * $baseDamage;

                    // Damage reduction from Towers
                    $damage *= (1 - min(1, $this->improvementCalculator->getImprovementMultiplierBonus($target, 'towers')));

                    $target->{$attr} += round($damage);
                }
            }

            $target->save([
                'event' => HistoryService::EVENT_ACTION_CAST_SPELL,
                'action' => $spellKey
            ]);

            // Surreal Perception
            $sourceDominionId = null;
            if ($this->spellCalculator->isSpellActive($target, 'surreal_perception')) {
                $sourceDominionId = $dominion->id;
            }

            $damageString = generate_sentence_from_array($damageDealt);

            $this->notificationService
                ->queueNotification('received_hostile_spell', [
                    'sourceDominionId' => $sourceDominionId,
                    'spellKey' => $spellKey,
                    'damageString' => $damageString,
                ])
                ->sendNotifications($target, 'irregular_dominion');

            if ($spellDeflected) {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, but it was deflected and your dominion lost %s.',
                        $damageString
                    ),
                    'alert-type' => 'danger'
                ];
            } else {
                return [
                    'success' => true,
                    'damage' => $totalDamage,
                    'message' => sprintf(
                        'Your wizards cast the spell successfully, your target lost %s.',
                        $damageString
                    )
                ];
            }
        }
    }

    /**
     * Returns the successful return message.
     *
     * Little e a s t e r e g g because I was bored.
     *
     * @param Dominion $dominion
     * @return string
     */
    protected function getReturnMessageString(Dominion $dominion): string
    {
        $wizards = $dominion->military_wizards;
        $archmages = $dominion->military_archmages;
        $spies = $dominion->military_spies;

        if (($wizards === 0) && ($archmages === 0)) {
            return 'You cast %s at a cost of %s mana.';
        }

        if ($wizards === 0) {
            if ($archmages > 1) {
                return 'Your archmages successfully cast %s at a cost of %s mana.';
            }

            $thoughts = [
                'mumbles something about being the most powerful sorceress in the dominion is a lonely job, "but somebody\'s got to do it"',
                'mumbles something about the food being quite delicious',
                'feels like a higher spiritual entity is watching her',
                'winks at you',
            ];

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_wizards') > 0) {
                $thoughts[] = 'carefully observes the trainee wizards';
            } else {
                $thoughts[] = 'mumbles something about the lack of student wizards to teach';
            }

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_archmages') > 0) {
                $thoughts[] = 'mumbles something about being a bit sad because she probably won\'t be the single most powerful sorceress in the dominion anymore';
                $thoughts[] = 'mumbles something about looking forward to discuss the secrets of arcane knowledge with her future peers';
            } else {
                $thoughts[] = 'mumbles something about not having enough peers to properly conduct her studies';
                $thoughts[] = 'mumbles something about feeling a bit lonely';
            }

            return ('Your archmage successfully casts %s at a cost of %s mana. In addition, she ' . $thoughts[array_rand($thoughts)] . '.');
        }

        if ($archmages === 0) {
            if ($wizards > 1) {
                return 'Your wizards successfully cast %s at a cost of %s mana.';
            }

            $thoughts = [
                'mumbles something about the food being very tasty',
                'has the feeling that an omnipotent being is watching him',
            ];

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_wizards') > 0) {
                $thoughts[] = 'mumbles something about being delighted by the new wizard trainees so he won\'t be lonely anymore';
            } else {
                $thoughts[] = 'mumbles something about not having enough peers to properly conduct his studies';
                $thoughts[] = 'mumbles something about feeling a bit lonely';
            }

            if ($this->queueService->getTrainingQueueTotalByResource($dominion, 'military_archmages') > 0) {
                $thoughts[] = 'mumbles something about looking forward to his future teacher';
            } else {
                $thoughts[] = 'mumbles something about not having an archmage master to study under';
            }

            if ($spies === 1) {
                $thoughts[] = 'mumbles something about fancying that spy lady';
            } elseif ($spies > 1) {
                $thoughts[] = 'mumbles something about thinking your spies are complotting against him';
            }

            return ('Your wizard successfully casts %s at a cost of %s mana. In addition, he ' . $thoughts[array_rand($thoughts)] . '.');
        }

        if (($wizards === 1) && ($archmages === 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your wizard and archmage successfully cast %s together in harmony at a cost of %s mana. It was glorious to behold.',
                'Your wizard watches in awe while his teacher archmage blissfully casts %s at a cost of %s mana.',
                'Your archmage facepalms as she observes her wizard student almost failing to cast %s at a cost of %s mana.',
                'Your wizard successfully casts %s at a cost of %s mana, while his teacher archmage watches him with pride.',
            ];

            return $strings[array_rand($strings)];
        }

        if (($wizards === 1) && ($archmages > 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your wizard was sleeping, so your archmages successfully cast %s at a cost of %s mana.',
                'Your wizard watches carefully while your archmages successfully cast %s at a cost of %s mana.',
            ];

            return $strings[array_rand($strings)];
        }

        if (($wizards > 1) && ($archmages === 1)) {
            $strings = [
                'Your wizards successfully cast %s at a cost of %s mana.',
                'Your archmage found herself lost in her study books, so your wizards successfully cast %s at a cost of %s mana.',
            ];

            return $strings[array_rand($strings)];
        }

        return 'Your wizards successfully cast %s at a cost of %s mana.';
    }

    /**
     * Calculate the XP (resource_tech) gained when casting a black-op.
     *
     * @param Dominion $dominion
     * @param Dominion $target
     * @param int $damage
     * @return int
     *
     */
    protected function calculateXpGain(Dominion $dominion, Dominion $target, int $damage): int
    {
      if($damage === 0 or $damage === NULL)
      {
        return 0;
      }
      else
      {
        $landRatio = $this->rangeCalculator->getDominionRange($dominion, $target) / 100;
        $base = 30;

        return $base * $landRatio;
      }
    }



}
