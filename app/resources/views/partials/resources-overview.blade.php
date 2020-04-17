@if (isset($selectedDominion) && !Route::is('dominion.status'))
    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title"><i class="fa fa-bar-chart"></i> {{ $selectedDominion->name }} Overview</h3>
        </div>

        <div class="box-body">

            <div class="row">
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Networth:</b></div>
                        <div class="col-lg-6">{{ number_format($networthCalculator->getDominionNetworth($selectedDominion)) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Platinum:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_platinum) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Food:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_food) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Ore:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_ore) }}</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-3">
                    <div class="row">
                      <div class="col-lg-6"><b>
                        @if ($selectedDominion->race->name == 'Growth')
                        Cells:
                        @elseif ($selectedDominion->race->name == 'Myconid')
                        Spores:
                        @elseif ($selectedDominion->race->name == 'Swarm')
                        Larvae:
                        @else
                        Peasants:
                        @endif
                        </div>
                        </b>
                        <div class="col-lg-6">{{ number_format($selectedDominion->peasants) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Lumber:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_lumber) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Mana:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_mana) }}</div>
                    </div>
                </div>
                <div class="col-xs-3">
                    <div class="row">
                        <div class="col-lg-6"><b>Gems:</b></div>
                        <div class="col-lg-6">{{ number_format($selectedDominion->resource_gems) }}</div>
                    </div>
                </div>
            </div>


            @if ($dominionProtectionService->canTick($selectedDominion))
            <div class="row">
                <div class="col-xs-12">
                    <form action="{{ route('dominion.status') }}" method="post" role="form" id="tick_form">
                    @csrf
                    <input type="hidden" name="returnTo" value="{{ Route::currentRouteName() }}">
                    <button type="submit"
                            class="btn btn-info"
                            {{ $selectedDominion->isLocked() ? 'disabled' : null }}
                            id="tick-button">
                        <i class="ra ra-shield"></i>
                        Proceed to next tick
                    </button>
                  </form>
                </div>
            </div>
            @endif

        </div>
    </div>


@endif
