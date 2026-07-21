# Tenant backup and restore

Version: v5.2.8

This update adds tenant-scoped backup and restore tools for SaaS operation.

## What is backed up

- Tenant record metadata
- Tenant settings
- Rows from tenant-scoped tables that have `tenant_id`
- Upload assets referenced by tenant rows when the file exists under `uploads/`

## Storage

Backups are saved under:

```text
storage/tenant_backups/{tenant_key}/{backup_id}.zip
```

## Restore safety

- Restore is owner-only.
- Restore is allowed only when the backup `tenant_key` matches the current tenant.
- Restore replaces only rows for the target `tenant_id`.
- Other tenants are not deleted or overwritten.

## Recommended operation

1. Open client settings.
2. Click Tenant Backup.
3. Create a backup before major changes.
4. Download the ZIP for offline storage when needed.
5. Restore only after confirming the target client and backup ID.
