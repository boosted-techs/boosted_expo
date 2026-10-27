<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegisterEntryRequest;
use App\Models\FinanceJournal;
use App\Models\FinancePostingRequest;
use App\Models\FinanceRegisterEntry;
use App\Services\FinanceRegisterPostings;
use App\Support\BusinessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceRegisterEntryController extends Controller
{
    private function own(Request $r, int $id, string $permission = 'finance.view'): FinanceRegisterEntry
    {
        abort_unless(app(BusinessPermissions::class)->allows($r->attributes->get('membership'), $permission), 403);

        return FinanceRegisterEntry::query()->where('business_id', $r->attributes->get('activeBusiness')->id)->findOrFail($id);
    }

    public function store(StoreRegisterEntryRequest $r, string $kind, FinanceRegisterPostings $register): RedirectResponse
    {
        $entry = $register->prepare($r->attributes->get('activeBusiness'), $kind, $r->validated(), $r->user()->id);

        return redirect()->route('finance.register.show', $entry->id)->with('status', 'Transaction prepared with its linked accounting entries. Attach evidence, then submit for independent finance review.');
    }

    public function show(Request $r, int $entry): View
    {
        $entry = $this->own($r, $entry)->load('asset', 'loan');
        $business = $r->attributes->get('activeBusiness');
        $review = FinancePostingRequest::query()->where('business_id', $business->id)->where('source_type', 'register_entry')->where('source_id', $entry->id)->first();
        $journal = FinanceJournal::query()->where('business_id', $business->id)->where('source_type', 'register_entry')->where('source_id', $entry->id)->first();
        $canPrepare = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.documents.create');
        $canSubmit = app(BusinessPermissions::class)->allows($r->attributes->get('membership'), 'finance.reviews.request');

        return view('finance.register-entry', compact('entry', 'business', 'review', 'journal', 'canPrepare', 'canSubmit'));
    }

    public function cancel(Request $r, int $entry, FinanceRegisterPostings $register): RedirectResponse
    {
        $entry = $this->own($r, $entry, 'finance.documents.create');
        $data = $r->validate(['reason' => ['required', 'string', 'min:20', 'max:3000']]);
        $register->cancel($entry, $r->user()->id, $data['reason']);

        return back()->with('status', 'Unsubmitted transaction withdrawn with its source retained.');
    }

    public function upload(Request $r, int $entry, FinanceRegisterPostings $register): RedirectResponse
    {
        $entry = $this->own($r, $entry, 'finance.documents.create');
        $r->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $register->attach($entry, $r->file('file'), $r->user()->id);

        return back()->with('status', 'Private register evidence attached.');
    }

    public function evidence(Request $r, int $entry, int $file): StreamedResponse
    {
        $entry = $this->own($r, $entry);
        $item = $entry->data_snapshot['evidence'][$file] ?? null;
        $disk = Storage::disk('local');
        abort_unless($item && str_starts_with($item['path'], 'register-evidence/'.$entry->business_id.'/') && $disk->exists($item['path']) && hash_equals($item['sha256'], hash_file('sha256', $disk->path($item['path']))), 404);

        return $disk->download($item['path'], 'register-'.$entry->id.'-'.$file.'.'.pathinfo($item['path'], PATHINFO_EXTENSION), ['Content-Type' => $item['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
