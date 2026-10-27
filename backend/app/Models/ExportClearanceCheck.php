<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportClearanceCheck extends Model
{
    protected $fillable = ['manifest_hash', 'file_path', 'file_name', 'file_mime', 'file_sha256', 'revoked_by', 'revoked_at', 'revocation_reason', 'business_id', 'shipment_id', 'document_kind', 'authority_name',
        'external_reference', 'valid_until', 'source_evidence', 'status',
        'submitted_by', 'reviewed_by', 'review_note', 'reviewed_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'valid_until' => 'date', 'reviewed_at' => 'datetime'];
    }
}
