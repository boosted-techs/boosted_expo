@extends('layouts.console')
@section('title', 'Import Products')
@section('content')
<div class="page-intro"><div><span class="eyebrow">EXCEL IMPORT</span><h1>Import products</h1><p>Upload a spreadsheet, review the items, then confirm before saving.</p></div></div>
<section class="section-card form-narrow">
<h2>Choose a file</h2>
<p class="muted">Supports Excel (.xlsx, .xls) and CSV. Look for columns named Product, Product Code or SKU, Description, Unit and Status.</p>
<form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data" class="form-stack">@csrf
<label class="field"><span>Spreadsheet</span><input type="file" name="file" accept=".xlsx,.xls,.csv" required><small>Up to 3 MB, 1,000 rows or 500 distinct products per import.</small></label>
<button class="primary-button" type="submit">Preview products</button>
</form>
</section>
<section class="section-card form-narrow">
<h2>Product import guidance</h2><p class="muted">Each company has its own catalogue. Products with matching SKU codes will be updated after confirmation, and products with new codes will be created.</p>
<p class="muted">If the file does not contain SKU codes, the system creates them from product names. Check the preview for duplicate products before confirming.</p>
<a class="secondary-button" href="{{ route('products.template') }}">Download Excel template</a>
</section>
<a href="{{ route('products.index') }}" class="text-button">← Back to products</a>
@endsection