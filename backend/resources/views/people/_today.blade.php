<section class="section-card attention-panel" aria-labelledby="attention-title">
  <div class="attention-heading"><div><h2 id="attention-title">What needs attention today?</h2><p>Due orders, assigned work and follow-ups · EAT</p></div><a href="{{ route('people.index') }}" class="secondary-button">My work & attendance <x-ui-icon name="arrow" /></a></div>
  <div class="attention-columns">
    <div class="attention-column">
      <div class="queue-title"><x-ui-icon name="orders" /><h3>Orders due</h3><span class="queue-count">{{ $todayWork['orders']->count() }}</span></div>
      @forelse($todayWork['orders']->take(3) as $o)
        <div class="attention-item"><a href="{{ route('sales-orders.show',$o) }}"><strong>{{ $o->reference }}</strong><small>{{ $o->customer->name }} · {{ $o->pricing_status==='unpriced'?'Pricing pending':'Pricing agreed' }}</small></a><span class="attention-date {{ $o->required_on->isBefore(today('Africa/Kampala')) ? 'overdue' : '' }}">{{ $o->required_on->isBefore(today('Africa/Kampala')) ? 'Overdue · ' : 'Due ' }}{{ $o->required_on->format('d M') }}</span></div>
      @empty<p class="queue-empty">You're up to date. No orders due.</p>@endforelse
      @if($todayWork['orders']->count()>3)<details class="attention-more"><summary>{{ $todayWork['orders']->count()-3 }} more orders</summary>@foreach($todayWork['orders']->skip(3) as $o)<div class="attention-item"><a href="{{ route('sales-orders.show',$o) }}"><strong>{{ $o->reference }} · {{ $o->customer->name }}</strong><small>Due {{ $o->required_on->format('d M') }}</small></a></div>@endforeach</details>@endif
    </div>
    <div class="attention-column">
      <div class="queue-title"><x-ui-icon name="people" /><h3>Tasks & shifts</h3><span class="queue-count">{{ $todayWork['tasks']->count()+$todayWork['shifts']->count()+$todayWork['trips']->count() }}</span></div>
      @if($todayWork['tasks']->isEmpty() && $todayWork['shifts']->isEmpty() && $todayWork['trips']->isEmpty())<p class="queue-empty">No work awaiting action.</p>@endif
      @foreach($todayWork['tasks']->take(3) as $t)<div class="attention-item"><strong>{{ $t->title }}</strong><small>{{ $t->person->name }}</small><span class="attention-date {{ $t->due_at->isPast()?'overdue':'' }}">{{ $t->due_at->isPast()?'Overdue · ':'Due ' }}{{ $t->due_at->timezone('Africa/Kampala')->format('d M, H:i') }}</span></div>@endforeach
      @if($todayWork['tasks']->count()>3)<details class="attention-more"><summary>{{ $todayWork['tasks']->count()-3 }} more tasks</summary>@foreach($todayWork['tasks']->skip(3) as $t)<div class="attention-item"><strong>{{ $t->title }}</strong><small>{{ $t->person->name }} · Due {{ $t->due_at->timezone('Africa/Kampala')->format('d M, H:i') }}</small></div>@endforeach</details>@endif
      @if($todayWork['trips']->isNotEmpty() || $todayWork['shifts']->isNotEmpty())<details class="attention-more"><summary>Trips & shifts ({{ $todayWork['trips']->count()+$todayWork['shifts']->count() }})</summary>
      @foreach($todayWork['trips'] as $trip)<div class="attention-item"><strong>{{ $trip->location }}</strong><small>{{ $trip->person?->name }} · {{ $trip->reference }}</small><span class="attention-date {{ $trip->due_at->isPast()?'overdue':'' }}">{{ $trip->due_at->isPast()?'Overdue · ':'Due ' }}{{ $trip->due_at->timezone('Africa/Kampala')->format('d M, H:i') }}</span></div>@endforeach
      @foreach($todayWork['shifts'] as $s)<div class="attention-item"><strong>{{ $s->person?->name??$s->user?->name }}</strong><small>{{ $s->location }} · {{ $s->starts_at->timezone('Africa/Kampala')->format('d M, H:i') }} @if($s->ends_at->isPast()&&$s->attendances->isEmpty()) · Attendance missing @endif</small></div>@endforeach
      </details>@endif
    </div>
    <div class="attention-column">
      <div class="queue-title"><x-ui-icon name="stock" /><h3>Follow-ups & quality</h3><span class="queue-count">{{ $todayWork['followups']->count()+$todayWork['stockCases']->count() }}</span></div>
      @if($todayWork['followups']->isEmpty() && $todayWork['stockCases']->isEmpty())<p class="queue-empty">No outstanding follow-ups.</p>@endif
      @foreach($todayWork['followups']->take(2) as $followup)<div class="attention-item"><a href="{{ route('partners.show',[$followup->supplier_id?'supplier':'customer',$followup->supplier_id??$followup->customer_id]) }}"><strong>{{ $followup->title }}</strong><small>{{ $followup->person->name }} · {{ $followup->status }}</small></a><span class="attention-date {{ $followup->due_at->isPast()?'overdue':'' }}">{{ $followup->due_at->isPast()?'Overdue · ':'Due ' }}{{ $followup->due_at->timezone('Africa/Kampala')->format('d M, H:i') }}</span></div>@endforeach
      @foreach($todayWork['stockCases']->take(2) as $case)<div class="attention-item"><a href="{{ route('stock.cases.index',['search'=>$case->reference]) }}#case-{{ $case->id }}"><strong>{{ ucfirst(str_replace('_',' ',$case->kind)) }}</strong><small>{{ $case->person?->name??'Unassigned' }} · {{ $case->status }}</small></a><span class="attention-date {{ $case->due_on?->isBefore(today('Africa/Kampala'))?'overdue':'' }}">{{ $case->due_on?->isBefore(today('Africa/Kampala'))?'Overdue · ':'Due ' }}{{ $case->due_on?->format('d M')??'date needed' }}</span></div>@endforeach
      @if($todayWork['followups']->count()>2 || $todayWork['stockCases']->count()>2)<details class="attention-more"><summary>More follow-ups</summary>
      @foreach($todayWork['followups']->skip(2) as $followup)<div class="attention-item"><a href="{{ route('partners.show',[$followup->supplier_id?'supplier':'customer',$followup->supplier_id??$followup->customer_id]) }}"><strong>{{ $followup->title }}</strong><small>{{ $followup->person->name }} · Due {{ $followup->due_at->timezone('Africa/Kampala')->format('d M, H:i') }}</small></a></div>@endforeach
      @foreach($todayWork['stockCases']->skip(2) as $case)<div class="attention-item"><a href="{{ route('stock.cases.index',['search'=>$case->reference]) }}#case-{{ $case->id }}"><strong>{{ $case->reference }} · {{ str_replace('_',' ',$case->kind) }}</strong><small>{{ $case->due_on?->format('d M')??'Deadline needed' }}</small></a></div>@endforeach
      </details>@endif
    </div>
  </div>
</section>
