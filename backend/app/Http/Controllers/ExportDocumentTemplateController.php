<?php

namespace App\Http\Controllers;

use App\Models\ExportDocumentTemplate;
use App\Services\ExportDocuments;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExportDocumentTemplateController extends Controller
{
    private function check(Request $request, string $permission): void
    {
        abort_unless(app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),$permission),403);
    }

    private function own(Request $request, int $id): ExportDocumentTemplate
    {
        return ExportDocumentTemplate::query()->where('business_id',
            $request->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    private function payload(Request $request, ?ExportDocumentTemplate $template=null): array
    {
        $businessId=$request->attributes->get('activeBusiness')->id;
        $request->merge([
            'visible_columns'=>$request->input('visible_columns',[]),
            'header_fields'=>$request->input('header_fields',[]),
        ]);
        $data=$request->validate([
            'name'=>['required','string','min:2','max:100',
                Rule::unique('export_document_templates','name')
                    ->where('business_id',$businessId)->ignore($template?->id)],
            'type'=>['required',Rule::in(array_keys(ExportDocuments::TYPES))],
            'paper_size'=>['required',Rule::in(['A4','Letter'])],
            'orientation'=>['required',Rule::in(['portrait','landscape'])],
            'visible_columns'=>['present','array','max:11'],
            'visible_columns.*'=>['string','distinct',Rule::in(array_keys(ExportDocuments::COLUMN_LABELS))],
            'header_fields'=>['present','array','max:8'],
            'header_fields.*'=>['string','distinct',Rule::in(array_keys(ExportDocuments::HEADER_LABELS))],
        ]);
        if ($data['type']==='packing_list' && count($data['visible_columns'])===0) {
            throw ValidationException::withMessages([
                'visible_columns'=>'Choose at least one packing-list table column.',
            ]);
        }
        if ($data['type']==='dispatch_note') $data['visible_columns']=[];
        return $data;
    }

    public function index(Request $request): View
    {
        $this->check($request,'documents.view');
        $businessId=$request->attributes->get('activeBusiness')->id;
        $templates=ExportDocumentTemplate::query()
            ->where('business_id',$businessId)->orderBy('type')->orderBy('name')->get();
        $canManage=app(BusinessPermissions::class)->allows(
            $request->attributes->get('membership'),'documents.templates.manage'
        );
        $types=ExportDocuments::TYPES;
        $columns=ExportDocuments::COLUMN_LABELS;
        $headers=ExportDocuments::HEADER_LABELS;
        return view('documents.templates',compact('templates','canManage','types','columns','headers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->check($request,'documents.templates.manage');
        $data=$this->payload($request);
        ExportDocumentTemplate::create([
            ...$data,'business_id'=>$request->attributes->get('activeBusiness')->id,
            'created_by'=>$request->user()->id,
        ]);
        return redirect()->route('document-templates.index')->with('status','Document template saved.');
    }

    public function edit(Request $request, int $template): View
    {
        $this->check($request,'documents.templates.manage');
        $template=$this->own($request,$template);
        $types=ExportDocuments::TYPES;
        $columns=ExportDocuments::COLUMN_LABELS;
        $headers=ExportDocuments::HEADER_LABELS;
        return view('documents.template-edit',compact('template','types','columns','headers'));
    }

    public function update(Request $request, int $template): RedirectResponse
    {
        $this->check($request,'documents.templates.manage');
        $template=$this->own($request,$template);
        $data=$this->payload($request,$template);
        DB::transaction(function() use($template,$data){
            $locked=ExportDocumentTemplate::query()->where('business_id',$template->business_id)
                ->lockForUpdate()->findOrFail($template->id);
            $locked->update([...$data,'version'=>$locked->version+1]);
        });
        return redirect()->route('document-templates.index')
            ->with('status','Document template updated. Previously issued documents remain unchanged.');
    }
}
