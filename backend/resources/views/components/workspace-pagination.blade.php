<div class="server-pagination" data-table-key="{{ $tableKey }}" data-row-start="{{ $paginator->firstItem() ?? 1 }}" data-record-total="{{ $paginator->total() }}" data-page-size="{{ $paginator->perPage() }}" data-table-columns="{{ json_encode($tableColumns) }}">
  <span>{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ number_format($paginator->total()) }}</span>
  @if($paginator->hasPages())<nav aria-label="Results pagination"><a @if($paginator->onFirstPage()) aria-disabled="true" @else href="{{ $paginator->previousPageUrl() }}" @endif>Previous</a>
  @foreach($elements as $element)
    @if(is_string($element))<span>{{ $element }}</span>@endif
    @if(is_array($element))@foreach($element as $page=>$url)<a href="{{ $url }}" @if($page === $paginator->currentPage()) aria-current="page" @endif>{{ $page }}</a>@endforeach @endif
  @endforeach<a @if($paginator->hasMorePages()) href="{{ $paginator->nextPageUrl() }}" @else aria-disabled="true" @endif>Next</a></nav>@endif
</div>
