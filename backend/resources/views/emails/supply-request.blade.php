<p>Hello {{ $supplier->name }},</p>
<p>{{ $supplyRequest->product->name }} has been requested for supply. You are invited to submit a quotation.</p>
<p><strong>Reference:</strong> {{ $supplyRequest->reference }}</p>
<p><strong>Required:</strong> {{ $supplyRequest->quantity }} {{ $supplyRequest->product->base_unit }}</p>
<p><strong>Quotation currency:</strong> {{ $supplyRequest->currency }}</p>
<p><strong>Deadline:</strong> {{ $supplyRequest->response_deadline->format('d M Y, H:i') }}</p>
<p><a href="{{ $offerUrl }}">Submit or revise your quotation</a></p>
<p>This link is private to your company and expires when the request closes. You do not need to create an account.</p>
<p>Boosted Export ERP</p>