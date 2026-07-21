# v5.1.8 Gacha Tenant Scope

SaaS mode hardening for the gacha feature.

## What changed

- Added tenant separation to gacha campaigns, rarities, prizes, entitlements, draw results, and purchase-interest logs.
- Existing gacha tables receive a nullable `tenant_id` column automatically when possible.
- Existing rows are assigned to the current tenant during the upgrade when a tenant context is active.
- Gacha rarity uniqueness is changed from global `code` to tenant-specific `(tenant_id, code)` so each client can define its own rarity settings.
- Admin summary, schedule grant list, entitlement grant, LINE notification, LIFF draw status, draw execution, recent result list, and purchase-interest list now read only the current tenant's gacha data.

## Notes

- If the shared server blocks `ALTER TABLE`, the service keeps running and the update page should not break. In that case, run the database migration manually before adding multiple clients.
- Client-specific gacha settings belong to the owner area. Event-based entitlement grants belong to the operation area.
- This update does not change the public LIFF URL. Tenant selection continues to rely on the SaaS tenant runtime introduced in earlier v5.1.x updates.
