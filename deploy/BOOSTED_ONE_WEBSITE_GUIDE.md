# Boosted One public website - content and QA guide

This repository contains one Laravel application with an authenticated ERP workspace
and a separate public marketing site intended for boostedone.online.

## Public routes

| Route | Purpose |
| --- | --- |
| / | Export-first product overview |
| /solutions | Industry and business use cases |
| /solutions/{slug} | Detailed business solution and workflow |
| /features | Module directory |
| /features/{slug} | Full feature explanation with stages and examples |
| /how-it-works | Export workflow and onboarding explanation |
| /pricing | Active, public subscription plans |
| /about | Purpose and Boosted Technologies identity |
| /contact | Contact details and help |
| /get-started | Existing onboarding request flow |
| /privacy-policy | Published Privacy Policy |
| /data-retention | Published Data Retention Policy |
| /terms-of-service | Published Terms of Service |
| /sitemap.xml | Public product site sitemap |

Feature and solution detail pages use structured content in
backend/config/boostedone_features.php and backend/config/boostedone_solutions.php.
New entries automatically receive detail URLs and sitemap inclusion. Document
verified features only. Do not claim external certification, physical printing
or live carrier/bank integration without production evidence.

## Typography and responsive design

The marketing stylesheet is backend/public/assets/boosted-one.css.
The ERP typography overrides are in backend/public/assets/modules.css,
loaded after expo.css. Desktop main text is 17px; small-screen main text
16.5px. Mobile input text is 16px or larger. Controls and data tables have
larger text but preserve scrollability for dense screens.

## Verification

From backend:

    php artisan view:cache
    php artisan test --filter=BoostedOneMarketingSiteTest
    php artisan test --compact
    php artisan route:list

Automated tests cover the main pages, details, privacy consent, sitemap,
public-only pricing and redirects. Real Chrome mobile-device emulation checked
320, 360 and 390 CSS px; tablet 768; desktop 1365. None reported horizontal
document overflow.

## Before production publication

1. Configure the real HTTPS domain and canonical APP_URL.
2. Policies were published on 24 October 2026 at the owner's instruction and are
   indexable and included in the sitemap. Verify privacy governance, contractual
   retention requirements and any independent legal review separately.
3. Confirm pricing, taxes, module terms and support before publishing fixed rates.
4. Verify production backups, security, invoicing and payment acceptance separately.
5. Use the controlled release process and keep customer data intact.

The public site does not create an ERP user account. Business onboarding remains
administrator reviewed; the business owner joins via invitation.
ERP customer commercial invoices and Boosted One subscription invoices stay separate.
