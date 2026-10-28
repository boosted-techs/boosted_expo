@if($charts['orders'] !== null || $charts['shipments'] !== null || count($metrics))
<div class="dashboard-charts">
  @if($charts['orders'] !== null)
    @php
      $series = $charts['orders'];
      $ceiling = max(1, max($series['values']));
      $points = collect($series['values'])->map(fn($value, $index) => (34 + $index * 26).','. (122 - $value / $ceiling * 104))->implode(' ');
    @endphp
    <section class="section-card chart-panel" aria-labelledby="orders-chart-title">
      <div class="panel-head"><h2 id="orders-chart-title">Order activity</h2><span class="tiny-pill">14 days</span></div>
      <p class="panel-subtitle">Orders confirmed per day · EAT</p>
      <div class="chart-total">{{ $series['total'] }} <small>orders</small></div>
      <svg class="line-chart" viewBox="0 0 390 138" role="img" aria-label="{{ $series['total'] }} orders confirmed over the last 14 days. Daily values are available below.">
        @foreach([0, 0.5, 1] as $tick)
          <line class="chart-gridline" x1="34" x2="372" y1="{{ 122 - $tick * 104 }}" y2="{{ 122 - $tick * 104 }}" />
          <text class="chart-axis" x="24" y="{{ 126 - $tick * 104 }}" text-anchor="end">{{ round($ceiling * $tick, 1) }}</text>
        @endforeach
        <polyline class="chart-line" points="{{ $points }}" />
        @foreach($series['values'] as $index => $value)
          <circle class="chart-dot" cx="{{ 34 + $index * 26 }}" cy="{{ 122 - $value / $ceiling * 104 }}" r="3"><title>{{ $series['labels'][$index] }}: {{ $value }} orders</title></circle>
        @endforeach
      </svg>
      <div class="chart-labels"><span>{{ $series['labels'][0] }}</span><span>{{ $series['labels'][6] }}</span><span>{{ $series['labels'][13] }}</span></div>
      @if($series['total'] === 0)<p class="chart-empty">No orders confirmed in this period.</p>@endif
      <details class="chart-data"><summary>View daily figures</summary><table><caption class="sr-only">Daily order confirmations</caption><thead><tr><th>Date</th><th>Orders</th></tr></thead><tbody>@foreach($series['values'] as $index => $value)<tr><th scope="row">{{ $series['labels'][$index] }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table></details>
    </section>
  @endif
  @if(count($metrics))
    <section class="section-card chart-panel" aria-labelledby="work-chart-title">
      <div class="panel-head"><h2 id="work-chart-title">Open work by stage</h2></div>
      <p class="panel-subtitle">Current record counts</p>
      <div class="bar-chart">
        @foreach($metrics as $card)
          <a class="bar-row" href="{{ route($card['route']) }}">
            <div class="bar-label"><span>{{ ['sales-orders.index'=>'Orders','stock.index'=>'Ready batches','shipments.index'=>'Preparing shipments','finance.reviews'=>'Finance reviews'][$card['route']] }}</span><strong>{{ number_format($card['value']) }}</strong></div>
            <div class="bar-track" aria-hidden="true"><div class="bar-fill" style="width:{{ $card['value'] / max(1, max(array_column($metrics, 'value'))) * 100 }}%"></div></div>
          </a>
        @endforeach
      </div>
    </section>
  @endif
  @if($charts['shipments'] !== null)
    @php
      $shipmentTotal = array_sum(array_column($charts['shipments'], 'value'));
      $offset = 0;
      $chartColors = ['var(--chart-blue)', 'var(--chart-gold)', 'var(--chart-violet)', 'var(--green)', 'var(--muted)'];
    @endphp
    <section class="section-card chart-panel" aria-labelledby="shipment-chart-title">
      <div class="panel-head"><h2 id="shipment-chart-title">Shipment status</h2></div><p class="panel-subtitle">All shipments</p>
      <div class="donut-layout">
        <div class="donut" aria-hidden="true">
          <svg viewBox="0 0 120 120"><circle class="donut-base" cx="60" cy="60" r="47" />
          @foreach($charts['shipments'] as $index => $slice)
            @php($percent = $shipmentTotal ? $slice['value'] / $shipmentTotal * 100 : 0)
            @if($percent > 0)<circle cx="60" cy="60" r="47" pathLength="100" style="stroke:{{ $chartColors[$index] }}" stroke-dasharray="{{ $percent }} {{ 100 - $percent }}" stroke-dashoffset="{{ -$offset }}" />@endif
            @php($offset += $percent)
          @endforeach
          </svg><div class="donut-center">{{ $shipmentTotal }}<small>shipments</small></div>
        </div>
        <ul class="chart-legend" aria-label="Shipment counts by status">
          @foreach($charts['shipments'] as $index => $slice)<li><span class="legend-dot" style="background:{{ $chartColors[$index] }}" aria-hidden="true"></span><span>{{ $slice['label'] }}</span><strong>{{ $slice['value'] }}</strong></li>@endforeach
        </ul>
      </div>
      @if($shipmentTotal === 0)<p class="chart-empty">No shipments yet.</p>@endif
    </section>
  @endif
</div>
@endif
