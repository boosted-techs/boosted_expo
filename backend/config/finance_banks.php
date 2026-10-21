<?php

return [
    /*
     * First-party, administrator-provisioned trust anchors only.
     * NEVER accept a user-uploaded public key as proof of bank identity.
     * External partner onboarding must verify the official issuer key.
     *
     * FINANCE_TRUSTED_BANK_ISSUERS_JSON example structure (no live keys):
     * {"official-bank-id":{"institution":"Bank Name",
     *  "key_id":"bank-signing-key-2026","public_key_pem":"-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
     *  "verified_out_of_band":true,"active":true}}
     */
    'trusted_issuers'=>json_decode(
        env('FINANCE_TRUSTED_BANK_ISSUERS_JSON','{}'),true
    )?:[],
];