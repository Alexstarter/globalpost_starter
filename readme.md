GlobalPost Starter
==================

This repository contains the source code for the **GlobalPost Shipping** PrestaShop module. The initial iteration delivers the scaffolding required to bootstrap further development:

- Module registration for PrestaShop 8+
- Composer/PSR-4 autoloading configuration
- Database installer that manages the `ps_globalpost_order` table
- Documentation and SQL schema dump for onboarding

Refer to [`modules/globalpostshipping/README.md`](modules/globalpostshipping/README.md) for installation and usage instructions.

## Packaging the Module

When you need an installable archive for QA or releases, generate it locally with the documented command:

```bash
zip -r dist/globalpostshipping.zip modules/globalpostshipping
```

Run the command from the repository root after your changes are merged. Attach the resulting ZIP to the release process (for example, a GitHub release asset or deployment ticket) instead of committing it to the repository.
