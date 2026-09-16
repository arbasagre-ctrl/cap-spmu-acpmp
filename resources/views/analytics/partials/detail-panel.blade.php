@php
    /*
    | One Analytics detail, rendered over the section that produced it.
    |
    | Every detail type returns the same shape from AnalyticsDetailService, so
    | this partial renders all of them and there is no second detail system to
    | keep in step. It explains a figure; it does not try to be a record list.
    | The formal record list is Reports, offered below as a secondary action.
    |
    | Closing is a link back to the same Analytics state without ?detail, so
    | the reader returns to exactly the tab and filters they came from.
    */
    $closeUrl = request()->fullUrlWithoutQuery(['detail', 'item', 'for', 'state', 'metric', 'bucket']);
@endphp

<section
    id="analytics-detail"
    class="analytics-detail-inline"
    aria-labelledby="analytics-detail-title"
    data-analytics-detail
    data-analytics-detail-panel
>
    <header class="analytics-detail-inline-head">
        <div>
            <p class="analytics-detail-context">{{ $detail['context'] }}</p>
            <h2 id="analytics-detail-title">{{ $detail['title'] }}</h2>
            <p class="analytics-detail-scope">
                {{ $detail['period_label'] }}
                @if($detail['filters'])
                    <span aria-hidden="true">·</span> {{ $detail['filters'] }}
                @endif
            </p>
        </div>

        <a
            class="icon-button analytics-detail-close"
            href="{{ $closeUrl }}"
            aria-label="Close detail"
            data-analytics-detail-close
        ><x-icon name="close" size="18" /></a>
    </header>

    <div class="analytics-detail-inline-body">
        @if($detail['value'] !== null)
            <p class="analytics-detail-figure">
                <strong>{{ is_numeric($detail['value']) ? number_format((float) $detail['value']) : $detail['value'] }}</strong>
                @if(! empty($detail['value_label']))
                    <span>{{ $detail['value_label'] }}</span>
                @endif
            </p>
        @endif

        @if($detail['note'] && ($detail['type'] ?? null) !== 'card')
            <p class="analytics-detail-note">{{ $detail['note'] }}</p>
        @endif

        @if($detail['stats'] !== [])
            <div class="analytics-stat-grid">
                @foreach($detail['stats'] as $stat)
                    <div class="analytics-stat is-static">
                        <span>{{ $stat['label'] }}</span>
                        <strong @class(['is-text' => ! is_numeric($stat['value'])])>
                            {{ is_numeric($stat['value']) ? number_format((float) $stat['value'], is_float($stat['value']) && floor($stat['value']) != $stat['value'] ? 2 : 0) : $stat['value'] }}
                        </strong>
                    </div>
                @endforeach
            </div>
        @endif

        @if(! empty($detail['formula']))
            <details class="analytics-explain">
                <summary>How this was calculated</summary>
                <div>
                    <p>Weighted moving average over the completed periods listed below.</p>
                    <p class="analytics-formula">{{ $detail['formula'] }}</p>
                    <p>Rounded to whole requests and never below zero. This is an estimate based on historical activity, not a guarantee.</p>
                </div>
            </details>
        @endif

        @if($detail['bars'])
            @if(! empty($detail['bars_title']))
                <h3 class="analytics-detail-subhead">{{ $detail['bars_title'] }}</h3>
            @endif

            <div class="analytics-bars">
                @foreach($detail['bars'] as $bar)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $bar['label'] }}</span>
                            <span class="analytics-bar-value">{{ $bar['value'] }}</span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $bar['share'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($detail['table'])
            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            @foreach($detail['table']['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($detail['table']['rows'] as $row)
                            <tr>
                                @foreach($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($detail['empty'])
            <p class="analytics-empty">{{ $detail['empty'] }}</p>
        @endif
    </div>

    @if($detail['reports_url'])
        <footer class="analytics-detail-inline-foot">
            <a class="button secondary ui-pressable" href="{{ $detail['reports_url'] }}">
                <span>View source records</span>
                <x-icon name="arrow-right" size="15" />
            </a>
        </footer>
    @endif
</section>
