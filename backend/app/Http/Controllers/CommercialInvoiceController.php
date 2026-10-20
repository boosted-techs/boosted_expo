<?php
namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\ExportShipment;
use App\Services\CommercialInvoiceIssuance;
use App\Support\BusinessPermissions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CommercialInvoiceController extends Controller
{
    private function check(Request $request,string $permission):void {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }
    private function own(Request $request,int $id):CommercialInvoice {
        return CommercialInvoice::query()->where('business_id',$request->attributes
            ->get('activeBusiness')->id)->findOrFail($id);
    }
    public function index(Request $request):View {
        $this->check($request,'invoices.view');
        $invoices=CommercialInvoice::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->with(['order','customer','shipment'])->latest()->paginate(20);
        return view('sales.invoices.index',compact('invoices'));
    }
    public function issue(Request $request,int $shipment,CommercialInvoiceIssuance $issuer):RedirectResponse {
        $this->check($request,'invoices.issue');
        $data=$request->validate([
            'freight_amount'=>['required','numeric','min:0','decimal:0,2','max:999999999999'],
            'insurance_amount'=>['required','numeric','min:0','decimal:0,2','max:999999999999'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $shipment=ExportShipment::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($shipment);
        $invoice=$issuer->issue($shipment,$data,$request->user()->id);
        return redirect()->route('commercial-invoices.show',$invoice->id)->with('status',
            'Commercial invoice issued. Amounts are recorded but not posted to the financial ledger.');
    }
    public function show(Request $request,int $invoice):View {
        $this->check($request,'invoices.view');
        $invoice=$this->own($request,$invoice)->load(['order','shipment','customer']);
        return view('sales.invoices.show',compact('invoice'));
    }
    public function print(Request $request,int $invoice):Response {
        $this->check($request,'invoices.view');
        $invoice=$this->own($request,$invoice);
        return response($invoice->html_snapshot,200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function pdf(Request $request,int $invoice):Response {
        $this->check($request,'invoices.view');
        $invoice=$this->own($request,$invoice);
        $options=new Options();
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $options->set('isJavascriptEnabled',false);
        $options->set('defaultFont','DejaVu Sans');
        $pdf=new Dompdf($options);
        $pdf->loadHtml($invoice->html_snapshot,'UTF-8');
        $pdf->setPaper('a4','portrait');$pdf->render();
        return response($pdf->output(),200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$invoice->reference.'.pdf"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
