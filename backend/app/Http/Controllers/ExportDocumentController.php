<?php

namespace App\Http\Controllers;

use App\Models\ExportDocument;
use App\Models\ExportDocumentTemplate;
use App\Models\ExportShipment;
use App\Services\ExportDocuments;
use App\Support\BusinessPermissions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ExportDocumentController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }

    private function own(Request $request, int $document): ExportDocument
    {
        return ExportDocument::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($document);
    }

    public function index(Request $request): View
    {
        $this->check($request,'documents.view');
        $documents=ExportDocument::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)
            ->with(['shipment','template'])->latest()->paginate(25);
        return view('documents.index',compact('documents'));
    }

    public function issue(Request $request, int $shipment, int $template,
        ExportDocuments $documents): RedirectResponse
    {
        $this->check($request,'documents.generate');
        $businessId=$request->attributes->get('activeBusiness')->id;
        $shipment=ExportShipment::query()->where('business_id',$businessId)->findOrFail($shipment);
        $template=ExportDocumentTemplate::query()->where('business_id',$businessId)
            ->where('active',true)->findOrFail($template);
        $document=$documents->issue($shipment,$template,$request->user()->id);
        return redirect()->route('export-documents.index')
            ->with('status','Issued '.$document->reference.'. Printable HTML and PDF are available.');
    }

    public function html(Request $request, int $document): Response
    {
        $this->check($request,'documents.view');
        $document=$this->own($request,$document);
        return response($document->html_snapshot,200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function pdf(Request $request, int $document): Response
    {
        $this->check($request,'documents.view');
        $document=$this->own($request,$document);
        $snapshot=$document->data_snapshot;
        $options=new Options();
        $options->set('isRemoteEnabled',false);
        $options->set('isPhpEnabled',false);
        $options->set('isJavascriptEnabled',false);
        $options->set('defaultFont','DejaVu Sans');
        $options->set('dpi',96);

        $pdf=new Dompdf($options);
        $pdf->loadHtml($document->html_snapshot,'UTF-8');
        $pdf->setPaper(
            $snapshot['template']['paper_size']==='Letter' ? 'letter' : 'a4',
            $snapshot['template']['orientation']==='landscape' ? 'landscape' : 'portrait',
        );
        $pdf->render();
        $filename=$document->reference.'.pdf';
        return response($pdf->output(),200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$filename.'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
