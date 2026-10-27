<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CommercialInvoice;
use App\Models\ExportDocument;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\LabelGeneration;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ReleaseIntegrityInspector
{
    // Read-only checks complement rather than replace financial audit or
    // external security evaluation. They must be safe against the actual
    // live database and must never alter operational or financial records.
    public function inspect(Business $business):array
    {
        $id=$business->id;
        $checks=[];

        $record=function(string $code,int $failures,string $description)use(&$checks):void{
            $checks[]=[
                'code'=>$code,
                'status'=>$failures===0?'ok':'fail',
                'failures'=>$failures,
                'detail'=>$description,
            ];
        };

        $owners=DB::table('business_memberships')
            ->where('business_id',$id)->where('role','owner')
            ->where('active',true)->count();
        $record('active_business_owner',$owners>=1?0:1,
            'At least one active owner must hold the release decision.');

        $financeErrors=0;
        FinanceJournal::query()->where('business_id',$id)
            ->with('lines')->orderBy('id')->chunkById(100,function($journals)use(&$financeErrors,$id){
                foreach($journals as $journal){
                    $srcDebit=BigDecimal::zero();$srcCredit=BigDecimal::zero();
                    $baseDebit=BigDecimal::zero();$baseCredit=BigDecimal::zero();
                    if($journal->lines->count()<2){
                        $financeErrors++;
                        continue;
                    }
                    $lineError=false;
                    foreach($journal->lines as $line){
                        if($line->business_id!==$id){
                            $lineError=true;break;
                        }
                        foreach(['debit','credit','base_debit','base_credit'] as $field){
                            if(BigDecimal::of((string)$line->{$field})->isLessThan(0)){
                                $lineError=true;break;
                            }
                        }
                        if($lineError)break;
                        $srcDebit=$srcDebit->plus((string)$line->debit);
                        $srcCredit=$srcCredit->plus((string)$line->credit);
                        $baseDebit=$baseDebit->plus((string)$line->base_debit);
                        $baseCredit=$baseCredit->plus((string)$line->base_credit);
                    }
                    if($lineError || !$srcDebit->isEqualTo($srcCredit)
                        || !$baseDebit->isEqualTo($baseCredit)
                        || $baseDebit->isEqualTo(0) && $srcDebit->isEqualTo(0)){
                        $financeErrors++;
                    }
                }
            });
        $record('double_entry_integrity',$financeErrors,
            'Every journal must have at least two tenant-scoped lines, nonnegative debits/credits and exact balanced source/base totals.');

        $stockTotals=[];$negativeStock=0;
        StockMovement::query()->where('business_id',$id)->orderBy('id')
            ->select(['id','batch_id','quantity'])->chunkById(500,function($movements)use(&$stockTotals){
                foreach($movements as $movement){
                    $batch=(int)$movement->batch_id;
                    $stockTotals[$batch]=($stockTotals[$batch]??BigDecimal::zero())
                        ->plus((string)$movement->quantity);
                }
            });
        foreach($stockTotals as $remaining){
            if($remaining->isLessThan(0))$negativeStock++;
        }
        $record('nonnegative_batch_balances',$negativeStock,
            'Every source batch must retain a nonnegative signed unpacked stock balance.');

        $invoiceErrors=0;
        CommercialInvoice::query()->where('business_id',$id)
            ->select(['id','sha256','html_snapshot'])->orderBy('id')
            ->chunkById(100,function($invoices)use(&$invoiceErrors){
                foreach($invoices as $invoice){
                    if(!$invoice->html_snapshot
                        || !hash_equals((string)$invoice->sha256,
                            hash('sha256',$invoice->html_snapshot)))$invoiceErrors++;
                }
            });
        $record('invoice_snapshot_integrity',$invoiceErrors,
            'Issued commercial invoices must retain their original matching HTML fingerprint.');

        $documentErrors=0;
        ExportDocument::query()->where('business_id',$id)
            ->select(['id','sha256','html_snapshot'])->orderBy('id')
            ->chunkById(100,function($documents)use(&$documentErrors){
                foreach($documents as $document){
                    if(!$document->html_snapshot
                        || !hash_equals((string)$document->sha256,
                            hash('sha256',$document->html_snapshot)))$documentErrors++;
                }
            });
        $record('export_document_integrity',$documentErrors,
            'Issued export documents must match their immutable HTML fingerprint.');

        $labelErrors=0;
        LabelGeneration::query()->where('business_id',$id)
            ->select(['id','zpl_sha256','zpl_snapshot'])->orderBy('id')
            ->chunkById(100,function($generations)use(&$labelErrors){
                foreach($generations as $generation){
                    if($generation->zpl_snapshot===null
                        || !hash_equals((string)$generation->zpl_sha256,
                            hash('sha256',(string)$generation->zpl_snapshot)))$labelErrors++;
                }
            });
        $record('label_history_integrity',$labelErrors,
            'Original carton label snapshots must match saved ZPL fingerprints.');

        $cargoErrors=DB::table('export_shipments as s')
            ->leftJoin('shipment_cartons as sc',function($join){
                $join->on('s.id','=','sc.shipment_id')
                    ->on('s.business_id','=','sc.business_id');
            })->where('s.business_id',$id)
            ->whereIn('s.status',['dispatched','delivered'])
            ->select('s.id')->groupBy('s.id')
            ->havingRaw('count(sc.id) = 0')->get()->count();
        $record('dispatched_cargo_allocations',$cargoErrors,
            'Dispatched or delivered shipments must retain their allocated cartons.');

        $unreviewed=DB::table('external_provider_events')->where('business_id',$id)
            ->whereIn('review_status',['unmatched','needs_review'])->count();
        $checks[]=[
            'code'=>'unreviewed_provider_messages','status'=>$unreviewed>0?'warning':'ok',
            'failures'=>0,'detail'=>$unreviewed.
                ' provider notices still require investigation; an external message is not proof of payment or arrival.',
        ];

        $errors=count(array_filter($checks,fn($c)=>$c['status']==='fail'));
        $warnings=count(array_filter($checks,fn($c)=>$c['status']==='warning'));
        return [
            'ready'=>$errors===0,'errors'=>$errors,'warnings'=>$warnings,
            'checked_at'=>now()->toIso8601String(),
            'business_id'=>$id,'checks'=>$checks,
        ];
    }
}
