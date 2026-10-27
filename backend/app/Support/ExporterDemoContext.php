<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use App\Services\FinancePosting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExporterDemoContext
{
    public Business $business;

    public array $users = [];

    public array $records = [];

    public array $files = [];

    public array $credentials = [];

    public array $links = [];

    public bool $reused = false;

    public string $password;

    public string $stage = 'initialisation';

    public function __construct()
    {
        $this->password = Str::password(20);
    }

    public function user(string $role): User
    {
        return $this->users[$role];
    }

    public function id(string $role): int
    {
        return $this->user($role)->id;
    }

    public function date(int $daysAgo = 0): string
    {
        return Carbon::now('Africa/Kampala')->subDays($daysAgo)->toDateString();
    }

    public function key(): string
    {
        return (string) Str::uuid();
    }

    public function note(string $text): string
    {
        return 'DEMO ONLY — '.$text.' No real payment, delivery or authority approval is represented.';
    }

    public function put(string $name, Model $record): Model
    {
        $this->records[$name] = $record;

        return $record;
    }

    public function get(string $name): Model
    {
        return $this->records[$name]->fresh();
    }

    public function input(array $extra = []): array
    {
        return [...['event_on' => $this->date(), 'request_key' => $this->key(), 'external_reference' => 'DEMO-'.Str::upper(Str::random(8)), 'reason' => $this->note('Illustrative source evidence prepared for training.')], ...$extra];
    }

    public function file(string $folder, string $name, string $content): string
    {
        $path = $folder.'/'.$this->business->id.'/'.$name;
        Storage::disk('local')->put($path, $content);
        $this->files[] = $path;

        return $path;
    }

    public function post(string $type, Model $source, ?string $date = null, ?int $rate = null): void
    {
        $date ??= $source->event_on?->toDateString() ?? $source->invoice_on?->toDateString() ?? $source->vendor_invoice_date?->toDateString() ?? $this->date();
        $rate ??= $source->exchange_rate_id;
        $service = app(FinancePosting::class);
        $request = $service->request($this->business, $type, $source->id, ['posting_on' => $date, 'exchange_rate_id' => $rate, 'basis_notes' => $this->note('Demonstrates independently reviewed source, currency and quantity evidence.')], $this->id('accountant'));
        $service->review($request, true, $this->id('owner'), $this->note('Independent demo owner review of source and carrying values.'));
        $service->post($request->fresh(), $this->id('accountant'));
    }
}
